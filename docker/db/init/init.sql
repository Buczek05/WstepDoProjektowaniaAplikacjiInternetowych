-- =============================================================================
-- NEXUS / NexusOS — Centralized Sales Management System
-- Multi-tenant e-commerce sales analytics.
--
-- Model: ELT / star schema.
--   1. SOURCE layer  — raw data lands here as it arrives:
--        orders + order_items (sales), traffic_daily (sessions for conversion),
--        ad_spend_daily (marketing — cannot be derived from orders).
--   2. DAILY BATCH    — rebuild_daily_facts(org, date) recomputes one day of
--        statistics from the source layer once per day.
--   3. FACT layer    — already-processed statistics the dashboard reads:
--        fact_sales_daily, fact_category_daily, fact_kpi_daily,
--        fact_region_daily, fact_marketing_daily.
--
-- The dashboard NEVER aggregates raw orders at request time — it reads the
-- fact_* tables produced by the batch. Source <-> fact separation is deliberate.
--
-- Schema changes require: docker compose down -v   (init runs once per volume).
-- =============================================================================

-- ----------------------------------------------------------------------------
-- ENUM types
-- ----------------------------------------------------------------------------
CREATE TYPE order_status   AS ENUM ('pending', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded');
CREATE TYPE region_status  AS ENUM ('performing', 'needs_attention', 'at_risk');
CREATE TYPE user_role      AS ENUM ('admin', 'manager', 'analyst', 'viewer');


-- =============================================================================
-- DIMENSIONS / MASTER DATA
-- =============================================================================

-- Tenant. Every business row is scoped by organization_id (multi-tenant).
CREATE TABLE organizations (
    id            SERIAL PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,                 -- 'Acme Corp'
    slug          VARCHAR(120) NOT NULL UNIQUE,          -- 'acme-corp'
    plan          VARCHAR(50)  NOT NULL DEFAULT 'Free',  -- 'Premium Workspace'
    base_currency CHAR(3)      NOT NULL DEFAULT 'USD',
    is_active     BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- Application users (login). Keeps the auth contract used by UsersRepository:
-- id, username, email, password, full_name, is_active, created_at, updated_at.
CREATE TABLE users (
    id              SERIAL PRIMARY KEY,
    organization_id INTEGER REFERENCES organizations(id) ON DELETE CASCADE,
    username        VARCHAR(50)  NOT NULL UNIQUE,
    email           VARCHAR(255) NOT NULL UNIQUE,
    password        TEXT         NOT NULL,                -- bcrypt hash only
    full_name       VARCHAR(100),
    role            user_role    NOT NULL DEFAULT 'viewer',
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- New registrations have no org yet -> attach to the first organization so the
-- freshly-registered user immediately sees the seeded analytics. Keeps the
-- existing registration code (which never sets organization_id) unchanged.
CREATE FUNCTION users_default_org() RETURNS TRIGGER AS $$
BEGIN
    IF NEW.organization_id IS NULL THEN
        SELECT id INTO NEW.organization_id FROM organizations ORDER BY id LIMIT 1;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_users_default_org
    BEFORE INSERT ON users
    FOR EACH ROW EXECUTE FUNCTION users_default_org();

-- Many-to-many: a user can belong to several organizations (workspaces). The
-- workspace switcher in the mockups ("Acme Corp / Premium Workspace") picks one
-- of these; users.organization_id holds the *currently active* workspace.
CREATE TABLE organization_members (
    organization_id INTEGER     NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    user_id         INTEGER     NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role            user_role   NOT NULL DEFAULT 'viewer',
    joined_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (organization_id, user_id)
);

-- Sales channels per tenant: own store + marketplaces.
CREATE TABLE channels (
    id              SERIAL PRIMARY KEY,
    organization_id INTEGER     NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    name            VARCHAR(80) NOT NULL,                 -- 'Official Website', 'Amazon Marketplace', 'eBay Store'
    code            VARCHAR(40) NOT NULL,                 -- 'website', 'amazon', 'ebay'
    type            VARCHAR(40) NOT NULL DEFAULT 'marketplace',  -- 'own_store' | 'marketplace'
    is_active       BOOLEAN     NOT NULL DEFAULT TRUE,
    UNIQUE (organization_id, code)
);

-- Product categories per tenant.
CREATE TABLE categories (
    id              SERIAL PRIMARY KEY,
    organization_id INTEGER     NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    name            VARCHAR(80) NOT NULL,                 -- 'Electronics', 'Home Office', 'Fashion', 'Other'
    slug            VARCHAR(80) NOT NULL,
    UNIQUE (organization_id, slug)
);

-- Product catalog per tenant. Order line items reference these (the demo
-- generator draws random products from here so sales look realistic).
CREATE TABLE products (
    id              SERIAL PRIMARY KEY,
    organization_id INTEGER       NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    category_id     INTEGER       NOT NULL REFERENCES categories(id),
    name            VARCHAR(160)  NOT NULL,
    base_price      NUMERIC(12,2) NOT NULL CHECK (base_price >= 0),
    is_active       BOOLEAN       NOT NULL DEFAULT TRUE
);
CREATE INDEX idx_products_org ON products (organization_id);

-- Countries / regions — global reference, shared across tenants.
CREATE TABLE countries (
    id             SERIAL PRIMARY KEY,
    iso_code       CHAR(2)     NOT NULL UNIQUE,           -- 'PL', 'DE', 'US'
    name           VARCHAR(80) NOT NULL,
    region_cluster VARCHAR(60)                            -- 'EU East Cluster', 'NA Cluster'
);

-- Customers per tenant (drives the "Recent Sales" name/avatar).
CREATE TABLE customers (
    id              SERIAL PRIMARY KEY,
    organization_id INTEGER      NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    full_name       VARCHAR(120) NOT NULL,
    email           VARCHAR(255),
    country_id      INTEGER REFERENCES countries(id),
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);


-- =============================================================================
-- SOURCE LAYER — raw data as it arrives (the batch reads from here)
-- =============================================================================

-- Orders: one row per placed order. Source for sales facts + "Recent Sales".
CREATE TABLE orders (
    id              SERIAL PRIMARY KEY,
    organization_id INTEGER       NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    order_code      VARCHAR(40)   NOT NULL,               -- '#ORD-2849'
    customer_id     INTEGER REFERENCES customers(id),
    channel_id      INTEGER       NOT NULL REFERENCES channels(id),
    country_id      INTEGER REFERENCES countries(id),
    status          order_status  NOT NULL DEFAULT 'pending',
    currency        CHAR(3)       NOT NULL DEFAULT 'USD',
    total_amount    NUMERIC(12,2) NOT NULL DEFAULT 0,     -- denormalized sum of items
    ordered_at      TIMESTAMPTZ   NOT NULL,
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT now(),
    UNIQUE (organization_id, order_code)
);
CREATE INDEX idx_orders_org_date ON orders (organization_id, ordered_at);

-- Order line items — give each order its category attribution.
CREATE TABLE order_items (
    id           SERIAL PRIMARY KEY,
    order_id     INTEGER       NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    category_id  INTEGER       NOT NULL REFERENCES categories(id),
    product_name VARCHAR(160)  NOT NULL,
    quantity     INTEGER       NOT NULL DEFAULT 1 CHECK (quantity > 0),
    unit_price   NUMERIC(12,2) NOT NULL CHECK (unit_price >= 0),
    line_total   NUMERIC(12,2) GENERATED ALWAYS AS (quantity * unit_price) STORED
);
CREATE INDEX idx_order_items_order ON order_items (order_id);

-- Traffic per channel per day — denominator for conversion rate (orders/sessions).
CREATE TABLE traffic_daily (
    id              SERIAL PRIMARY KEY,
    organization_id INTEGER NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    channel_id      INTEGER REFERENCES channels(id),
    stat_date       DATE    NOT NULL,
    sessions        INTEGER NOT NULL DEFAULT 0,
    visitors        INTEGER NOT NULL DEFAULT 0,
    UNIQUE (organization_id, channel_id, stat_date)
);

-- Marketing spend per platform per day. CANNOT be derived from orders — it is
-- its own source feed (Amazon Ad Spend, Google Ads ROI, Meta Conversions).
CREATE TABLE ad_spend_daily (
    id                 SERIAL PRIMARY KEY,
    organization_id    INTEGER       NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    platform           VARCHAR(40)   NOT NULL,            -- 'amazon_ads','google_ads','meta'
    channel_id         INTEGER REFERENCES channels(id),
    stat_date          DATE          NOT NULL,
    spend              NUMERIC(12,2) NOT NULL DEFAULT 0,
    budget             NUMERIC(12,2),
    conversions        INTEGER       NOT NULL DEFAULT 0,
    attributed_revenue NUMERIC(12,2) NOT NULL DEFAULT 0,
    UNIQUE (organization_id, platform, stat_date)
);

-- KPI targets shown as "Target: $15k" on the cards.
CREATE TABLE metric_targets (
    id              SERIAL PRIMARY KEY,
    organization_id INTEGER       NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    metric_key      VARCHAR(60)   NOT NULL,               -- 'total_revenue','total_orders','conversion_rate',...
    period          VARCHAR(20)   NOT NULL,               -- 'daily' | 'monthly'
    period_start    DATE          NOT NULL,
    target_value    NUMERIC(16,4) NOT NULL,
    UNIQUE (organization_id, metric_key, period, period_start)
);


-- =============================================================================
-- FACT LAYER — already-processed statistics (the dashboard reads these)
-- =============================================================================

-- Audit log of the daily batch. One row per (org, day) recompute.
CREATE TABLE processing_runs (
    id              SERIAL PRIMARY KEY,
    organization_id INTEGER     NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    run_date        DATE        NOT NULL,                 -- the stat date recomputed
    status          VARCHAR(20) NOT NULL DEFAULT 'success',
    rows_written    INTEGER     NOT NULL DEFAULT 0,
    started_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    finished_at     TIMESTAMPTZ,
    message         TEXT
);
CREATE INDEX idx_processing_runs_org_date ON processing_runs (organization_id, run_date);

-- Additive sales by channel + country. Grain: org / date / channel / country.
-- orders_count is correct here because one order maps to one channel+country.
CREATE TABLE fact_sales_daily (
    id              BIGSERIAL PRIMARY KEY,
    organization_id INTEGER       NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    stat_date       DATE          NOT NULL,
    channel_id      INTEGER REFERENCES channels(id),
    country_id      INTEGER REFERENCES countries(id),
    gross_revenue   NUMERIC(14,2) NOT NULL DEFAULT 0,
    orders_count    INTEGER       NOT NULL DEFAULT 0,
    units_sold      INTEGER       NOT NULL DEFAULT 0,
    UNIQUE (organization_id, stat_date, channel_id, country_id)
);
CREATE INDEX idx_fact_sales_org_date ON fact_sales_daily (organization_id, stat_date);

-- Sales by category (from order_items). Grain: org / date / category.
-- Separate table because an order can span multiple categories (orders_count
-- would double-count), so we only carry additive revenue/units here.
CREATE TABLE fact_category_daily (
    id              BIGSERIAL PRIMARY KEY,
    organization_id INTEGER       NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    stat_date       DATE          NOT NULL,
    category_id     INTEGER       NOT NULL REFERENCES categories(id),
    gross_revenue   NUMERIC(14,2) NOT NULL DEFAULT 0,
    units_sold      INTEGER       NOT NULL DEFAULT 0,
    UNIQUE (organization_id, stat_date, category_id)
);

-- Generic KPI snapshot — powers every "card" (value + target + %Δ vs previous)
-- across Dashboard / Sales / Marketing / Global. Non-additive metrics
-- (conversion rate, ROI, growth index) live here, not in the additive facts.
CREATE TABLE fact_kpi_daily (
    id              BIGSERIAL PRIMARY KEY,
    organization_id INTEGER       NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    stat_date       DATE          NOT NULL,
    scope           VARCHAR(20)   NOT NULL DEFAULT 'overall',  -- 'overall','sales','marketing','global'
    metric_key      VARCHAR(60)   NOT NULL,               -- 'total_revenue','total_orders','conversion_rate',...
    metric_value    NUMERIC(16,4) NOT NULL,
    target_value    NUMERIC(16,4),
    prev_value      NUMERIC(16,4),                        -- previous comparable period (e.g. day before)
    delta_pct       NUMERIC(7,2),                         -- +12.0, -0.4, ...
    unit            VARCHAR(16),                          -- 'currency','count','percent','ratio'
    UNIQUE (organization_id, stat_date, scope, metric_key)
);
CREATE INDEX idx_fact_kpi_org_date ON fact_kpi_daily (organization_id, stat_date);

-- Global page: per-country card (revenue, MoM%, top channel + share, top
-- category, status). Grain: org / date / country.
CREATE TABLE fact_region_daily (
    id                BIGSERIAL PRIMARY KEY,
    organization_id   INTEGER       NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    stat_date         DATE          NOT NULL,
    country_id        INTEGER       NOT NULL REFERENCES countries(id),
    total_revenue     NUMERIC(14,2) NOT NULL DEFAULT 0,
    mom_pct           NUMERIC(7,2),                       -- +14.2 month-over-month
    top_channel_id    INTEGER REFERENCES channels(id),
    top_channel_share NUMERIC(5,2),                       -- 42.00 (%)
    top_category_id   INTEGER REFERENCES categories(id),
    status            region_status NOT NULL DEFAULT 'performing',
    UNIQUE (organization_id, stat_date, country_id)
);

-- Marketing page: ROI trend per platform. Grain: org / date / platform.
CREATE TABLE fact_marketing_daily (
    id                 BIGSERIAL PRIMARY KEY,
    organization_id    INTEGER       NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
    stat_date          DATE          NOT NULL,
    platform           VARCHAR(40)   NOT NULL,            -- 'amazon_ads','google_ads','meta'
    ad_spend           NUMERIC(14,2) NOT NULL DEFAULT 0,
    attributed_revenue NUMERIC(14,2) NOT NULL DEFAULT 0,
    roi                NUMERIC(8,2),                       -- attributed_revenue / ad_spend
    conversions        INTEGER       NOT NULL DEFAULT 0,
    UNIQUE (organization_id, stat_date, platform)
);


-- =============================================================================
-- DAILY BATCH — recompute one day of facts from the source layer.
-- In production this is run once per day (cron). Idempotent: re-running for the
-- same (org, date) replaces that day's facts.
-- =============================================================================
CREATE FUNCTION rebuild_daily_facts(p_org INTEGER, p_date DATE) RETURNS INTEGER AS $$
DECLARE
    v_rows        INTEGER := 0;
    v_prev_rev    NUMERIC;
    v_prev_ord    NUMERIC;
    v_prev_conv   NUMERIC;
    v_revenue     NUMERIC;
    v_orders      INTEGER;
    v_sessions    INTEGER;
    v_conversion  NUMERIC;
BEGIN
    -- Wipe this day's facts (idempotent re-run).
    DELETE FROM fact_sales_daily     WHERE organization_id = p_org AND stat_date = p_date;
    DELETE FROM fact_category_daily  WHERE organization_id = p_org AND stat_date = p_date;
    DELETE FROM fact_kpi_daily       WHERE organization_id = p_org AND stat_date = p_date;
    DELETE FROM fact_region_daily    WHERE organization_id = p_org AND stat_date = p_date;
    DELETE FROM fact_marketing_daily WHERE organization_id = p_org AND stat_date = p_date;

    -- 1) Sales by channel + country (revenue-bearing orders only).
    INSERT INTO fact_sales_daily (organization_id, stat_date, channel_id, country_id, gross_revenue, orders_count, units_sold)
    SELECT o.organization_id, p_date, o.channel_id, o.country_id,
           SUM(o.total_amount),
           COUNT(*),
           COALESCE(SUM(oi.units), 0)
    FROM orders o
    LEFT JOIN (
        SELECT order_id, SUM(quantity) AS units FROM order_items GROUP BY order_id
    ) oi ON oi.order_id = o.id
    WHERE o.organization_id = p_org AND o.ordered_at::date = p_date
      AND o.status NOT IN ('cancelled','refunded')
    GROUP BY o.organization_id, o.channel_id, o.country_id;
    GET DIAGNOSTICS v_rows = ROW_COUNT;

    -- 2) Sales by category (line items).
    INSERT INTO fact_category_daily (organization_id, stat_date, category_id, gross_revenue, units_sold)
    SELECT o.organization_id, p_date, oi.category_id, SUM(oi.line_total), SUM(oi.quantity)
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    WHERE o.organization_id = p_org AND o.ordered_at::date = p_date
      AND o.status NOT IN ('cancelled','refunded')
    GROUP BY o.organization_id, oi.category_id;

    -- 3) Marketing ROI per platform (from the ad-spend source feed).
    INSERT INTO fact_marketing_daily (organization_id, stat_date, platform, ad_spend, attributed_revenue, roi, conversions)
    SELECT a.organization_id, p_date, a.platform, a.spend, a.attributed_revenue,
           CASE WHEN a.spend > 0 THEN ROUND(a.attributed_revenue / a.spend, 2) END,
           a.conversions
    FROM ad_spend_daily a
    WHERE a.organization_id = p_org AND a.stat_date = p_date;

    -- 4) Per-country card with top channel + share and top category.
    INSERT INTO fact_region_daily (organization_id, stat_date, country_id, total_revenue, mom_pct,
                                   top_channel_id, top_channel_share, top_category_id, status)
    SELECT p_org, p_date, t.country_id, t.rev,
           NULL,                                          -- MoM filled by a separate monthly pass
           t.top_channel_id, t.top_share, t.top_category_id,
           'performing'
    FROM (
        SELECT o.country_id,
               SUM(o.total_amount) AS rev,
               (SELECT channel_id FROM orders x
                  WHERE x.organization_id = p_org AND x.country_id = o.country_id
                    AND x.ordered_at::date = p_date AND x.status NOT IN ('cancelled','refunded')
                  GROUP BY channel_id ORDER BY SUM(total_amount) DESC LIMIT 1) AS top_channel_id,
               ROUND(100.0 * (SELECT MAX(s) FROM (
                        SELECT SUM(total_amount) s FROM orders x
                          WHERE x.organization_id = p_org AND x.country_id = o.country_id
                            AND x.ordered_at::date = p_date AND x.status NOT IN ('cancelled','refunded')
                          GROUP BY channel_id) z)
                     / NULLIF(SUM(o.total_amount), 0), 2) AS top_share,
               (SELECT oi.category_id FROM order_items oi JOIN orders xo ON xo.id = oi.order_id
                  WHERE xo.organization_id = p_org AND xo.country_id = o.country_id
                    AND xo.ordered_at::date = p_date AND xo.status NOT IN ('cancelled','refunded')
                  GROUP BY oi.category_id ORDER BY SUM(oi.line_total) DESC LIMIT 1) AS top_category_id
        FROM orders o
        WHERE o.organization_id = p_org AND o.ordered_at::date = p_date
          AND o.status NOT IN ('cancelled','refunded') AND o.country_id IS NOT NULL
        GROUP BY o.country_id
    ) t;

    -- 5) Headline KPIs (value + target + %Δ vs previous day).
    SELECT COALESCE(SUM(gross_revenue),0), COALESCE(SUM(orders_count),0)
      INTO v_revenue, v_orders
      FROM fact_sales_daily WHERE organization_id = p_org AND stat_date = p_date;

    SELECT COALESCE(SUM(sessions),0) INTO v_sessions
      FROM traffic_daily WHERE organization_id = p_org AND stat_date = p_date;

    v_conversion := CASE WHEN v_sessions > 0 THEN ROUND(100.0 * v_orders / v_sessions, 2) ELSE 0 END;

    -- previous-day values for the delta
    SELECT metric_value INTO v_prev_rev  FROM fact_kpi_daily
        WHERE organization_id = p_org AND stat_date = p_date - 1 AND scope='overall' AND metric_key='total_revenue';
    SELECT metric_value INTO v_prev_ord  FROM fact_kpi_daily
        WHERE organization_id = p_org AND stat_date = p_date - 1 AND scope='overall' AND metric_key='total_orders';
    SELECT metric_value INTO v_prev_conv FROM fact_kpi_daily
        WHERE organization_id = p_org AND stat_date = p_date - 1 AND scope='overall' AND metric_key='conversion_rate';

    INSERT INTO fact_kpi_daily (organization_id, stat_date, scope, metric_key, metric_value, target_value, prev_value, delta_pct, unit)
    VALUES
      (p_org, p_date, 'overall', 'total_revenue', v_revenue,
         (SELECT target_value FROM metric_targets WHERE organization_id=p_org AND metric_key='total_revenue' AND period='daily' AND period_start=p_date),
         v_prev_rev,
         CASE WHEN v_prev_rev > 0 THEN ROUND(100.0*(v_revenue-v_prev_rev)/v_prev_rev,2) END, 'currency'),
      (p_org, p_date, 'overall', 'total_orders', v_orders,
         (SELECT target_value FROM metric_targets WHERE organization_id=p_org AND metric_key='total_orders' AND period='daily' AND period_start=p_date),
         v_prev_ord,
         CASE WHEN v_prev_ord > 0 THEN ROUND(100.0*(v_orders-v_prev_ord)/v_prev_ord,2) END, 'count'),
      (p_org, p_date, 'overall', 'conversion_rate', v_conversion,
         (SELECT target_value FROM metric_targets WHERE organization_id=p_org AND metric_key='conversion_rate' AND period='daily' AND period_start=p_date),
         v_prev_conv,
         CASE WHEN v_prev_conv > 0 THEN ROUND(v_conversion-v_prev_conv,2) END, 'percent');

    -- Audit row.
    INSERT INTO processing_runs (organization_id, run_date, status, rows_written, finished_at, message)
    VALUES (p_org, p_date, 'success', v_rows, now(), 'rebuild_daily_facts');

    RETURN v_rows;
END;
$$ LANGUAGE plpgsql;


-- =============================================================================
-- DEMO DATA GENERATORS — populate a tenant with realistic random source data.
-- =============================================================================

-- Global counter for generated order codes (#ORD-00001, ...).
CREATE SEQUENCE seq_order_code START 10000;

-- Give an org the standard channels + categories (idempotent).
CREATE FUNCTION seed_org_dimensions(p_org INTEGER) RETURNS void AS $$
BEGIN
    INSERT INTO channels (organization_id, name, code, type) VALUES
        (p_org, 'Official Website',   'website', 'own_store'),
        (p_org, 'Amazon Marketplace', 'amazon',  'marketplace'),
        (p_org, 'eBay Store',         'ebay',    'marketplace')
    ON CONFLICT (organization_id, code) DO NOTHING;

    INSERT INTO categories (organization_id, name, slug) VALUES
        (p_org, 'Electronics', 'electronics'),
        (p_org, 'Home Office', 'home-office'),
        (p_org, 'Fashion',     'fashion'),
        (p_org, 'Other',       'other')
    ON CONFLICT (organization_id, slug) DO NOTHING;
END;
$$ LANGUAGE plpgsql;

-- Create a pool of customers for an org if it has none.
CREATE FUNCTION seed_org_customers(p_org INTEGER, p_count INTEGER DEFAULT 15) RETURNS void AS $$
DECLARE
    firsts TEXT[] := ARRAY['Alex','Maria','John','Emma','Liam','Olivia','Noah','Ava','Lucas','Mia','Hugo','Lena','Piotr','Anna','Tom'];
    lasts  TEXT[] := ARRAY['Smith','Rodriguez','White','Brown','Müller','Kowalski','Nowak','Jensen','Rossi','Dubois','Garcia','Lee','Khan','Novak','Wagner'];
    ctys   INTEGER[];
    i INTEGER;
BEGIN
    IF EXISTS (SELECT 1 FROM customers WHERE organization_id = p_org) THEN
        RETURN;
    END IF;
    SELECT array_agg(id) INTO ctys FROM countries;
    FOR i IN 1..p_count LOOP
        INSERT INTO customers (organization_id, full_name, email, country_id)
        VALUES (
            p_org,
            firsts[1 + floor(random()*array_length(firsts,1))::int] || ' ' || lasts[1 + floor(random()*array_length(lasts,1))::int],
            'customer' || i || '.org' || p_org || '@example.com',
            ctys[1 + floor(random()*array_length(ctys,1))::int]
        );
    END LOOP;
END;
$$ LANGUAGE plpgsql;

-- Generate random orders + traffic + ad spend for an org across [p_from, p_to],
-- set targets, then run the daily batch for every day. This is the "raw data
-- arrives, then nightly processing builds the facts" flow, condensed.
CREATE FUNCTION seed_org_demo(p_org INTEGER, p_from DATE, p_to DATE,
                              p_min_orders INTEGER DEFAULT 8, p_max_orders INTEGER DEFAULT 24) RETURNS void AS $$
DECLARE
    chan  INTEGER[];
    cats  INTEGER[];
    ctys  INTEGER[];
    custs INTEGER[];
    prods INTEGER[];
    d DATE; i INTEGER; j INTEGER; n INTEGER; idx INTEGER;
    v_oid INTEGER; v_amount NUMERIC; v_qty INTEGER; v_price NUMERIC; v_cat INTEGER; v_pname TEXT;
    v_status order_status;
BEGIN
    PERFORM seed_org_dimensions(p_org);
    PERFORM seed_org_customers(p_org);

    SELECT array_agg(id) INTO chan  FROM channels   WHERE organization_id = p_org;
    SELECT array_agg(id) INTO cats  FROM categories WHERE organization_id = p_org;
    SELECT array_agg(id) INTO ctys  FROM countries;
    SELECT array_agg(id) INTO custs FROM customers  WHERE organization_id = p_org;
    SELECT array_agg(id) INTO prods FROM products    WHERE organization_id = p_org AND is_active;

    FOR d IN SELECT generate_series(p_from, p_to, INTERVAL '1 day')::date LOOP
        n := p_min_orders + floor(random()*(p_max_orders - p_min_orders + 1))::int;
        FOR i IN 1..n LOOP
            v_status := (ARRAY['delivered','delivered','delivered','delivered','processing','processing','pending','cancelled'])
                          [1 + floor(random()*8)::int]::order_status;
            INSERT INTO orders (organization_id, order_code, customer_id, channel_id, country_id, status, total_amount, ordered_at)
            VALUES (
                p_org,
                '#ORD-' || lpad(nextval('seq_order_code')::text, 5, '0'),
                custs[1 + floor(random()*array_length(custs,1))::int],
                chan [1 + floor(random()*array_length(chan,1))::int],
                ctys [1 + floor(random()*array_length(ctys,1))::int],
                v_status, 0,
                d + (random() * INTERVAL '20 hours')
            ) RETURNING id INTO v_oid;

            v_amount := 0;
            FOR j IN 1..(1 + floor(random()*3)::int) LOOP   -- 1..3 line items
                IF prods IS NOT NULL THEN
                    -- draw a real product from the catalog (price jittered ±10%).
                    -- NB: pick the index into a variable first — random() in a WHERE
                    -- clause is volatile and re-evaluated per row, which would match
                    -- the wrong row count.
                    idx := 1 + floor(random()*array_length(prods,1))::int;
                    SELECT category_id, name, round((base_price * (0.9 + random()*0.2))::numeric, 2)
                      INTO v_cat, v_pname, v_price
                      FROM products WHERE id = prods[idx];
                ELSE
                    v_cat   := cats[1 + floor(random()*array_length(cats,1))::int];
                    v_pname := 'Product #' || v_cat || '-' || j;
                    v_price := round((20 + random()*480)::numeric, 2);
                END IF;
                v_qty   := 1 + floor(random()*3)::int;
                INSERT INTO order_items (order_id, category_id, product_name, quantity, unit_price)
                VALUES (v_oid, v_cat, v_pname, v_qty, v_price);
                v_amount := v_amount + v_qty * v_price;
            END LOOP;
            UPDATE orders SET total_amount = v_amount WHERE id = v_oid;
        END LOOP;

        INSERT INTO traffic_daily (organization_id, channel_id, stat_date, sessions, visitors)
        SELECT p_org, c, d, (800 + floor(random()*4000))::int, (700 + floor(random()*3500))::int
        FROM unnest(chan) c
        ON CONFLICT DO NOTHING;

        INSERT INTO ad_spend_daily (organization_id, platform, stat_date, spend, budget, conversions, attributed_revenue)
        SELECT p_org, v.p, d, v.s, round(v.s * 1.2, 2), (50 + floor(random()*400))::int, round((v.s * (2 + random()*3))::numeric, 2)
        FROM (VALUES
            ('amazon_ads', round((2000 + random()*12000)::numeric, 2)),
            ('google_ads', round((1500 + random()*9000)::numeric, 2)),
            ('meta',       round(( 800 + random()*5000)::numeric, 2))
        ) AS v(p, s)
        ON CONFLICT DO NOTHING;
    END LOOP;

    INSERT INTO metric_targets (organization_id, metric_key, period, period_start, target_value)
    SELECT p_org, m.mk, 'daily', gs::date, m.tv
    FROM generate_series(p_from, p_to, INTERVAL '1 day') gs
    CROSS JOIN (VALUES ('total_revenue', 15000::numeric), ('total_orders', 200), ('conversion_rate', 4.5)) AS m(mk, tv)
    ON CONFLICT DO NOTHING;

    FOR d IN SELECT generate_series(p_from, p_to, INTERVAL '1 day')::date LOOP
        PERFORM rebuild_daily_facts(p_org, d);
    END LOOP;
END;
$$ LANGUAGE plpgsql;


-- =============================================================================
-- SEED — one tenant matching the mockups, so the dashboard is non-empty.
-- Register a user via /register to log in; the trigger attaches them to org #1.
-- =============================================================================
INSERT INTO organizations (name, slug, plan) VALUES
    ('Acme Corp', 'acme-corp', 'Premium Workspace');

INSERT INTO countries (iso_code, name, region_cluster) VALUES
    ('PL', 'Poland',         'EU East Cluster'),
    ('DE', 'Germany',        'EU Central Cluster'),
    ('US', 'United States',  'NA Cluster'),
    ('GB', 'United Kingdom', 'EU West Cluster'),
    ('FR', 'France',         'EU West Cluster'),
    ('ES', 'Spain',          'EU South Cluster'),
    ('IT', 'Italy',          'EU South Cluster'),
    ('NL', 'Netherlands',    'EU Central Cluster'),
    ('CA', 'Canada',         'NA Cluster');

INSERT INTO channels (organization_id, name, code, type) VALUES
    (1, 'Official Website',   'website', 'own_store'),
    (1, 'Amazon Marketplace', 'amazon',  'marketplace'),
    (1, 'eBay Store',         'ebay',    'marketplace');

INSERT INTO categories (organization_id, name, slug) VALUES
    (1, 'Electronics', 'electronics'),
    (1, 'Home Office', 'home-office'),
    (1, 'Fashion',     'fashion'),
    (1, 'Other',       'other');

INSERT INTO customers (organization_id, full_name, email, country_id) VALUES
    (1, 'Alex Smith',      'alex.smith@example.com',      3),
    (1, 'Maria Rodriguez', 'maria.rodriguez@example.com', 2),
    (1, 'John White',      'john.white@example.com',      1);

-- Orders matching the "Recent Sales" mockup.
INSERT INTO orders (organization_id, order_code, customer_id, channel_id, country_id, status, total_amount, ordered_at) VALUES
    (1, '#ORD-2849', 1, 1, 3, 'delivered',  842.00,  '2023-10-24 10:15:00+00'),
    (1, '#ORD-2848', 2, 2, 2, 'processing', 1250.50, '2023-10-23 14:40:00+00'),
    (1, '#ORD-2847', 3, 3, 1, 'pending',    215.00,  '2023-10-23 09:05:00+00');

INSERT INTO order_items (order_id, category_id, product_name, quantity, unit_price) VALUES
    (1, 1, 'Wireless Headphones', 1, 842.00),
    (2, 2, 'Standing Desk',       1, 1250.50),
    (3, 3, 'Running Jacket',      1, 215.00);

INSERT INTO traffic_daily (organization_id, channel_id, stat_date, sessions, visitors) VALUES
    (1, 1, '2023-10-24', 3200, 2800),
    (1, 2, '2023-10-24', 1500, 1300),
    (1, 3, '2023-10-24',  700,  650),
    (1, 2, '2023-10-23', 1400, 1200),
    (1, 3, '2023-10-23',  600,  560);

INSERT INTO ad_spend_daily (organization_id, platform, stat_date, spend, budget, conversions, attributed_revenue) VALUES
    (1, 'amazon_ads', '2023-10-24', 12450.00, 15000.00, 320, 38000.00),
    (1, 'google_ads', '2023-10-24',  8200.00, 10000.00, 410, 39524.00),
    (1, 'meta',       '2023-10-24',  4100.00,  5000.00, 132, 11500.00);

INSERT INTO metric_targets (organization_id, metric_key, period, period_start, target_value) VALUES
    (1, 'total_revenue',   'daily', '2023-10-24', 15000),
    (1, 'total_orders',    'daily', '2023-10-24', 200),
    (1, 'conversion_rate', 'daily', '2023-10-24', 4.5);

-- Run the daily batch (oldest first so the %Δ has a previous day to compare).
SELECT rebuild_daily_facts(1, DATE '2023-10-23');
SELECT rebuild_daily_facts(1, DATE '2023-10-24');

-- More companies (workspaces) the test user will belong to.
INSERT INTO organizations (name, slug, plan) VALUES
    ('Globex Trading',  'globex',   'Premium Workspace'),  -- id 2
    ('Initech Systems', 'initech',  'Pro Workspace'),       -- id 3
    ('Umbrella Retail', 'umbrella', 'Free');                -- id 4

-- Test user. Login:  test@nexus.dev  /  Test1234!   (bcrypt, role admin).
-- Active workspace = Acme Corp (organization_id = 1); member of all 4 below.
INSERT INTO users (organization_id, username, email, password, full_name, role) VALUES
    (1, 'testuser', 'test@nexus.dev',
     '$2y$10$lZcdBLmd9j39/WktB0VI0.m4/CFEcMyOhXLfq3DF8kurNtXQ0zKvi',
     'Test User', 'admin');

-- Memberships: the test user belongs to several companies (workspace switcher).
INSERT INTO organization_members (organization_id, user_id, role)
SELECT o.id, u.id,
       CASE WHEN o.id IN (1, 2) THEN 'admin'::user_role
            WHEN o.id = 3       THEN 'manager'::user_role
            ELSE 'analyst'::user_role END
FROM organizations o CROSS JOIN users u
WHERE u.username = 'testuser';

-- Ensure companies 2-4 have the standard categories/channels before we attach
-- their product catalogs (org 1 already got them in the seed above).
SELECT seed_org_dimensions(2);
SELECT seed_org_dimensions(3);
SELECT seed_org_dimensions(4);

-- Realistic product catalogs for the first four companies.
INSERT INTO products (organization_id, category_id, name, base_price)
SELECT o.id, c.id, p.name, p.price
FROM (VALUES
    (1, 'electronics', 'Sony WH-1000XM5 Wireless Headphones', 349.99),
    (1, 'electronics', 'Apple iPad Air 11"',                  599.00),
    (1, 'electronics', 'Samsung 4K Smart Monitor 32"',        429.99),
    (1, 'electronics', 'Anker 100W USB-C Charger',             59.99),
    (1, 'electronics', 'Logitech MX Master 3S Mouse',          99.99),
    (1, 'home-office', 'FlexiSpot Standing Desk 140cm',        429.00),
    (1, 'home-office', 'Herman Miller Sayl Chair',             545.00),
    (1, 'home-office', 'BenQ ScreenBar Monitor Light',         109.00),
    (1, 'home-office', 'Orbitkey Desk Mat',                     49.00),
    (1, 'fashion',     'Patagonia Nano Puff Jacket',           229.00),
    (1, 'fashion',     'Nike Air Zoom Pegasus 41',             139.99),
    (1, 'fashion',     'Levis 501 Original Jeans',              98.00),
    (1, 'other',       'Hydro Flask 32oz Bottle',               44.95),
    (1, 'other',       'Moleskine Classic Notebook',            24.95),
    (2, 'electronics', 'Bose QuietComfort Earbuds II',         279.00),
    (2, 'electronics', 'Dell UltraSharp 27" Monitor',          519.99),
    (2, 'electronics', 'Keychron K8 Mechanical Keyboard',       89.99),
    (2, 'home-office', 'Autonomous SmartDesk Core',            399.00),
    (2, 'home-office', 'Steelcase Series 1 Chair',             415.00),
    (2, 'home-office', 'Elgato Stream Deck MK.2',              149.99),
    (2, 'fashion',     'The North Face Resolve Jacket',         99.99),
    (2, 'fashion',     'Adidas Ultraboost Light',              189.99),
    (2, 'other',       'Yeti Rambler Tumbler 20oz',             38.00),
    (2, 'other',       'LEGO Icons Bonsai Tree',                49.99),
    (3, 'electronics', 'Garmin Forerunner 265',                449.99),
    (3, 'electronics', 'Kindle Paperwhite 11th Gen',           159.99),
    (3, 'electronics', 'JBL Flip 6 Bluetooth Speaker',         129.95),
    (3, 'home-office', 'IKEA Bekant Sit/Stand Desk',           329.00),
    (3, 'home-office', 'Fellowes Monitor Riser',                39.99),
    (3, 'fashion',     'Uniqlo Ultra Light Down Vest',          59.90),
    (3, 'fashion',     'New Balance 574 Sneakers',             109.99),
    (3, 'other',       'Stanley Quencher H2.0 40oz',            45.00),
    (4, 'electronics', 'GoPro HERO12 Black',                   399.99),
    (4, 'electronics', 'Razer DeathAdder V3 Mouse',             69.99),
    (4, 'electronics', 'TP-Link Deco Mesh WiFi (3-pack)',      199.99),
    (4, 'home-office', 'Branch Ergonomic Chair',               339.00),
    (4, 'home-office', 'Logitech MX Keys S Keyboard',          109.99),
    (4, 'fashion',     'Columbia Powder Lite Jacket',          130.00),
    (4, 'fashion',     'Vans Old Skool Shoes',                  74.99),
    (4, 'other',       'Owala FreeSip Water Bottle',            27.99)
) AS p(org, cat, name, price)
JOIN organizations o ON o.id = p.org
JOIN categories  c ON c.organization_id = o.id AND c.slug = p.cat;

-- Generate a full year of realistic data for every company, then build facts.
-- (Raw orders/traffic/ad-spend arrive, then the daily batch processes them.)
SELECT seed_org_demo(1, CURRENT_DATE - 364, CURRENT_DATE);
SELECT seed_org_demo(2, CURRENT_DATE - 364, CURRENT_DATE);
SELECT seed_org_demo(3, CURRENT_DATE - 364, CURRENT_DATE);
SELECT seed_org_demo(4, CURRENT_DATE - 364, CURRENT_DATE);


-- =============================================================================
-- ADDITIONAL COMPANIES (8) — realistic catalogs + customers, each a member
-- workspace of the test user. Generated content, one self-contained DO block
-- per company. seed_org_demo() builds a full year of orders/facts for each.
-- =============================================================================
DO $$
DECLARE v_org INTEGER;
BEGIN
  INSERT INTO organizations (name, slug, plan) VALUES ('Voltify', 'voltify', 'Premium Workspace') RETURNING id INTO v_org;
  PERFORM seed_org_dimensions(v_org);

  INSERT INTO products (organization_id, category_id, name, base_price)
  SELECT v_org, c.id, p.name, p.price
  FROM (VALUES
    ('electronics', 'Sony WH-1000XM5 Headphones', 349.99),
    ('electronics', 'Apple AirPods Pro 2nd Gen', 249.00),
    ('electronics', 'Samsung 65-inch QLED TV', 1299.00),
    ('electronics', 'LG C3 55-inch OLED TV', 1099.99),
    ('electronics', 'Bose SoundLink Flex Speaker', 149.00),
    ('electronics', 'Sony PlayStation 5 Console', 499.99),
    ('electronics', 'Nintendo Switch OLED', 349.99),
    ('electronics', 'GoPro Hero 12 Black', 399.99),
    ('electronics', 'DJI Mini 4 Pro Drone', 759.00),
    ('electronics', 'Logitech MX Master 3S Mouse', 99.99),
    ('electronics', 'Razer BlackWidow V4 Keyboard', 139.99),
    ('electronics', 'Apple Watch Series 9 45mm', 429.00),
    ('electronics', 'Garmin Forerunner 265 Watch', 349.99),
    ('electronics', 'Kindle Paperwhite 11th Gen', 139.99),
    ('electronics', 'iPad Air 5th Gen 64GB', 599.00),
    ('home-office', 'Anker 10-Port USB Hub', 49.99),
    ('home-office', 'Belkin MagSafe Charger Pad', 39.99),
    ('home-office', 'UGREEN 100W GaN Charger', 59.99),
    ('home-office', 'TP-Link WiFi 6 Router AX3000', 89.99),
    ('home-office', 'Seagate 2TB Portable Drive', 69.99),
    ('home-office', 'SanDisk 1TB Extreme SSD', 109.99),
    ('home-office', 'Elgato Stream Deck MK2', 149.99),
    ('home-office', 'Corsair 16GB DDR5 RAM Kit', 89.99),
    ('fashion', 'Nike Air Max 270 Sneakers', 150.00),
    ('fashion', 'Adidas Ultraboost 23 Shoes', 190.00),
    ('fashion', 'Ray-Ban Aviator Classic Sunglasses', 161.00),
    ('fashion', 'Fossil Gen 6 Hybrid Smartwatch', 149.00),
    ('fashion', 'Herschel Little America Backpack', 109.99),
    ('fashion', 'Incase 16-inch Laptop Sleeve', 49.95),
    ('fashion', 'Nomad Modern Leather Case', 59.95),
    ('fashion', 'Peak Design Everyday Backpack 20L', 279.95),
    ('other', 'Anker PowerCore 26800 Battery', 69.99),
    ('other', 'Tile Pro Bluetooth Tracker 4-Pack', 99.99),
    ('other', 'Amazon Echo Dot 5th Gen', 49.99),
    ('other', 'Google Nest Hub 2nd Gen', 99.99),
    ('other', 'Philips Hue Starter Kit 3-Pack', 139.99),
    ('other', 'TP-Link Kasa Smart Plug 4-Pack', 34.99),
    ('other', 'Wyze Cam v3 Indoor Camera', 35.98),
    ('other', 'Corsair iCUE H150i Elite Cooler', 179.99)
  ) AS p(cat, name, price)
  JOIN categories c ON c.organization_id = v_org AND c.slug = p.cat;

  INSERT INTO customers (organization_id, full_name, email, country_id)
  SELECT v_org, x.fn, x.em, co.id
  FROM (VALUES
    ('Anna Nowak', 'anna.nowak@voltify.example.com', 'PL'),
    ('James Carter', 'james.carter@voltify.example.com', 'US'),
    ('Laura Schmidt', 'laura.schmidt@voltify.example.com', 'DE'),
    ('Thomas Green', 'thomas.green@voltify.example.com', 'GB'),
    ('Marie Dupont', 'marie.dupont@voltify.example.com', 'FR'),
    ('Carlos Garcia', 'carlos.garcia@voltify.example.com', 'ES'),
    ('Giulia Russo', 'giulia.russo@voltify.example.com', 'IT'),
    ('Lars Visser', 'lars.visser@voltify.example.com', 'NL'),
    ('Sophie Tremblay', 'sophie.tremblay@voltify.example.com', 'CA'),
    ('Piotr Kowalski', 'piotr.kowalski@voltify.example.com', 'PL'),
    ('Emily Johnson', 'emily.johnson@voltify.example.com', 'US'),
    ('Felix Mueller', 'felix.mueller@voltify.example.com', 'DE'),
    ('Oliver Brown', 'oliver.brown@voltify.example.com', 'GB'),
    ('Camille Bernard', 'camille.bernard@voltify.example.com', 'FR'),
    ('Diego Martinez', 'diego.martinez@voltify.example.com', 'ES'),
    ('Francesca Conti', 'francesca.conti@voltify.example.com', 'IT'),
    ('Nina de Vries', 'nina.devries@voltify.example.com', 'NL'),
    ('Ethan Wilson', 'ethan.wilson@voltify.example.com', 'CA'),
    ('Magdalena Wisniewska', 'magdalena.wisniewska@voltify.example.com', 'PL'),
    ('Ryan Thompson', 'ryan.thompson@voltify.example.com', 'US'),
    ('Hannah Weber', 'hannah.weber@voltify.example.com', 'DE'),
    ('Jack Taylor', 'jack.taylor@voltify.example.com', 'GB'),
    ('Lea Moreau', 'lea.moreau@voltify.example.com', 'FR'),
    ('Alejandro Lopez', 'alejandro.lopez@voltify.example.com', 'ES'),
    ('Marco Ferrari', 'marco.ferrari@voltify.example.com', 'IT'),
    ('Bram Janssen', 'bram.janssen@voltify.example.com', 'NL'),
    ('Isabelle Gagnon', 'isabelle.gagnon@voltify.example.com', 'CA'),
    ('Krzysztof Wojcik', 'krzysztof.wojcik@voltify.example.com', 'PL'),
    ('Mia Anderson', 'mia.anderson@voltify.example.com', 'US'),
    ('Jonas Becker', 'jonas.becker@voltify.example.com', 'DE'),
    ('Charlotte Davies', 'charlotte.davies@voltify.example.com', 'GB'),
    ('Hugo Lefevre', 'hugo.lefevre@voltify.example.com', 'FR'),
    ('Lucia Fernandez', 'lucia.fernandez@voltify.example.com', 'ES'),
    ('Matteo Ricci', 'matteo.ricci@voltify.example.com', 'IT'),
    ('Daan Bakker', 'daan.bakker@voltify.example.com', 'NL')
  ) AS x(fn, em, iso)
  JOIN countries co ON co.iso_code = x.iso;

  INSERT INTO organization_members (organization_id, user_id, role)
  SELECT v_org, id, 'admin'::user_role FROM users WHERE username = 'testuser';

  PERFORM seed_org_demo(v_org, CURRENT_DATE - 364, CURRENT_DATE);
END $$;

DO $$
DECLARE v_org INTEGER;
BEGIN
  INSERT INTO organizations (name, slug, plan) VALUES ('DeskNest', 'desknest', 'Pro Workspace') RETURNING id INTO v_org;
  PERFORM seed_org_dimensions(v_org);

  INSERT INTO products (organization_id, category_id, name, base_price)
  SELECT v_org, c.id, p.name, p.price
  FROM (VALUES
    ('home-office', 'FlexiSpot E7 Pro Standing Desk', 499.99),
    ('home-office', 'Autonomous SmartDesk Pro 72-inch', 649.00),
    ('home-office', 'Uplift V2 Commercial Desk 60-inch', 879.00),
    ('home-office', 'Herman Miller Aeron Chair Size B', 1495.00),
    ('home-office', 'Steelcase Leap V2 Office Chair', 1299.00),
    ('home-office', 'Secretlab Titan Evo 2022 Chair', 549.00),
    ('home-office', 'Branch Ergonomic Chair', 329.00),
    ('home-office', 'Humanscale M8.1 Monitor Arm', 319.00),
    ('home-office', 'Ergotron LX Dual Monitor Arm', 219.99),
    ('home-office', 'BenQ ScreenBar Halo Monitor Light', 219.00),
    ('home-office', 'Elfa Drawer Cabinet 3-Drawer', 149.99),
    ('home-office', 'Poppin Box File Cabinet 2-Drawer', 199.00),
    ('home-office', 'Safco Steel Bookcase 5-Shelf', 179.99),
    ('home-office', 'IKEA KALLAX 4x4 Shelf Unit', 229.00),
    ('electronics', 'LG UltraWide 34-inch Monitor', 699.99),
    ('electronics', 'Dell UltraSharp 27-inch 4K Monitor', 579.00),
    ('electronics', 'BenQ PD3220U 32-inch Designer Monitor', 799.00),
    ('electronics', 'Logitech MX Keys S Keyboard', 109.99),
    ('electronics', 'Microsoft Sculpt Ergonomic Keyboard', 89.99),
    ('electronics', 'Logitech MX Vertical Mouse', 99.99),
    ('electronics', 'Dell WB7022 4K Webcam', 199.99),
    ('electronics', 'Jabra Evolve2 55 Headset', 379.00),
    ('electronics', 'Blue Yeti USB Microphone', 129.99),
    ('electronics', 'Elgato Key Light Air Panel', 199.99),
    ('electronics', 'CalDigit TS4 Thunderbolt 4 Dock', 349.99),
    ('fashion', 'Troubadour Apex Backpack', 295.00),
    ('fashion', 'Bellroy Classic Backpack 20L', 199.00),
    ('fashion', 'Timbuk2 Authority Pack Deluxe', 149.00),
    ('fashion', 'Herschel Supply Co Retreat Backpack', 89.99),
    ('fashion', 'Moleskine Classic Notebook Large', 24.99),
    ('fashion', 'Leuchtturm1917 Bullet Journal A5', 29.99),
    ('fashion', 'Pen and Gear Desk Organizer Set', 34.99),
    ('other', 'Flexispot Cable Management Tray', 39.99),
    ('other', 'SIIG Desk Grommet Cable Organizer', 19.99),
    ('other', 'Durable Monitor Stand with Drawer', 49.99),
    ('other', 'Fellowes Laptop Riser', 54.99),
    ('other', 'AmazonBasics Desk Pad 31x17 inch', 14.99),
    ('other', 'Logitech Spotlight Presentation Remote', 129.99),
    ('other', 'Kensington Desktop Wrist Rest', 29.99),
    ('other', 'Aelfox Footrest Under Desk Adjustable', 39.99)
  ) AS p(cat, name, price)
  JOIN categories c ON c.organization_id = v_org AND c.slug = p.cat;

  INSERT INTO customers (organization_id, full_name, email, country_id)
  SELECT v_org, x.fn, x.em, co.id
  FROM (VALUES
    ('Katarzyna Leszczynska', 'katarzyna.leszczynska@desknest.example.com', 'PL'),
    ('Michael Harris', 'michael.harris@desknest.example.com', 'US'),
    ('Tobias Schneider', 'tobias.schneider@desknest.example.com', 'DE'),
    ('Emma Williams', 'emma.williams@desknest.example.com', 'GB'),
    ('Juliette Petit', 'juliette.petit@desknest.example.com', 'FR'),
    ('Pablo Sanchez', 'pablo.sanchez@desknest.example.com', 'ES'),
    ('Chiara Lombardi', 'chiara.lombardi@desknest.example.com', 'IT'),
    ('Wouter Smit', 'wouter.smit@desknest.example.com', 'NL'),
    ('Megan Lavoie', 'megan.lavoie@desknest.example.com', 'CA'),
    ('Bartosz Kaczmarek', 'bartosz.kaczmarek@desknest.example.com', 'PL'),
    ('Ashley Robinson', 'ashley.robinson@desknest.example.com', 'US'),
    ('Petra Hoffmann', 'petra.hoffmann@desknest.example.com', 'DE'),
    ('George Clarke', 'george.clarke@desknest.example.com', 'GB'),
    ('Antoine Leroy', 'antoine.leroy@desknest.example.com', 'FR'),
    ('Elena Romero', 'elena.romero@desknest.example.com', 'ES'),
    ('Luca Marino', 'luca.marino@desknest.example.com', 'IT'),
    ('Fleur van den Berg', 'fleur.vandenberg@desknest.example.com', 'NL'),
    ('Nathan Bouchard', 'nathan.bouchard@desknest.example.com', 'CA'),
    ('Agnieszka Pawlak', 'agnieszka.pawlak@desknest.example.com', 'PL'),
    ('Daniel Walker', 'daniel.walker@desknest.example.com', 'US'),
    ('Sabine Richter', 'sabine.richter@desknest.example.com', 'DE'),
    ('Amelia Jones', 'amelia.jones@desknest.example.com', 'GB'),
    ('Valerie Girard', 'valerie.girard@desknest.example.com', 'FR'),
    ('Javier Torres', 'javier.torres@desknest.example.com', 'ES'),
    ('Valentina Gallo', 'valentina.gallo@desknest.example.com', 'IT'),
    ('Roel Meijer', 'roel.meijer@desknest.example.com', 'NL'),
    ('Chloe Bergeron', 'chloe.bergeron@desknest.example.com', 'CA'),
    ('Michal Adamski', 'michal.adamski@desknest.example.com', 'PL'),
    ('Joshua Martinez', 'joshua.martinez@desknest.example.com', 'US'),
    ('Klaus Wagner', 'klaus.wagner@desknest.example.com', 'DE'),
    ('Sophia Evans', 'sophia.evans@desknest.example.com', 'GB'),
    ('Nicolas Fontaine', 'nicolas.fontaine@desknest.example.com', 'FR'),
    ('Carmen Navarro', 'carmen.navarro@desknest.example.com', 'ES'),
    ('Roberto Esposito', 'roberto.esposito@desknest.example.com', 'IT'),
    ('Tim Dekker', 'tim.dekker@desknest.example.com', 'NL')
  ) AS x(fn, em, iso)
  JOIN countries co ON co.iso_code = x.iso;

  INSERT INTO organization_members (organization_id, user_id, role)
  SELECT v_org, id, 'manager'::user_role FROM users WHERE username = 'testuser';

  PERFORM seed_org_demo(v_org, CURRENT_DATE - 364, CURRENT_DATE);
END $$;
DO $$
DECLARE v_org INTEGER;
BEGIN
  INSERT INTO organizations (name, slug, plan) VALUES ('Threadline', 'threadline', 'Premium Workspace') RETURNING id INTO v_org;
  PERFORM seed_org_dimensions(v_org);

  INSERT INTO products (organization_id, category_id, name, base_price)
  SELECT v_org, c.id, p.name, p.price
  FROM (VALUES
    ('fashion', 'Classic Slim Fit Jeans', 79.00),
    ('fashion', 'Relaxed Chino Trousers', 65.00),
    ('fashion', 'Merino Wool Crew Neck Sweater', 110.00),
    ('fashion', 'Oxford Button-Down Shirt', 55.00),
    ('fashion', 'Slim Fit Blazer', 180.00),
    ('fashion', 'Floral Print Midi Dress', 89.00),
    ('fashion', 'High-Waist Yoga Leggings', 60.00),
    ('fashion', 'Puffer Winter Jacket', 195.00),
    ('fashion', 'Canvas Low-Top Sneakers', 75.00),
    ('fashion', 'Leather Chelsea Boots', 220.00),
    ('fashion', 'Ribbed Turtleneck Top', 48.00),
    ('fashion', 'Wide-Leg Linen Trousers', 72.00),
    ('fashion', 'Striped Breton T-Shirt', 35.00),
    ('fashion', 'Denim Jacket', 120.00),
    ('fashion', 'Knitted Cardigan', 95.00),
    ('fashion', 'Flare Leg Jeans', 85.00),
    ('fashion', 'Rain Resistant Windbreaker', 145.00),
    ('fashion', 'Satin Wrap Blouse', 58.00),
    ('fashion', 'Cargo Shorts', 49.00),
    ('fashion', 'Wool Blend Overcoat', 290.00),
    ('electronics', 'Wireless Bluetooth Earbuds', 99.00),
    ('electronics', 'USB-C Fast Charger 65W', 34.99),
    ('electronics', 'Portable Power Bank 20000mAh', 49.99),
    ('electronics', 'Smartwatch Fitness Tracker', 149.00),
    ('electronics', 'Bluetooth Speaker Mini', 59.00),
    ('home-office', 'Ergonomic Mesh Office Chair', 259.00),
    ('home-office', 'Adjustable Standing Desk', 399.00),
    ('home-office', 'Monitor Arm Dual Mount', 89.00),
    ('home-office', 'Desk Cable Management Kit', 19.99),
    ('home-office', 'Bamboo Desk Organizer', 29.99),
    ('home-office', 'LED Desk Lamp with USB Port', 44.99),
    ('home-office', 'Under-Desk Keyboard Tray', 39.99),
    ('home-office', 'Laptop Riser Stand', 34.99),
    ('home-office', 'Fabric Storage Ottoman', 79.00),
    ('home-office', 'Wall-Mounted Coat Rack', 55.00),
    ('other', 'Hardcover Ruled Notebook A5', 14.95),
    ('other', 'Stainless Steel Water Bottle 750ml', 24.95),
    ('other', 'Reusable Tote Bag', 12.00),
    ('other', 'Branded Gift Card 50', 50.00),
    ('other', 'Cotton Drawstring Bag', 9.99)
  ) AS p(cat, name, price)
  JOIN categories c ON c.organization_id = v_org AND c.slug = p.cat;

  INSERT INTO customers (organization_id, full_name, email, country_id)
  SELECT v_org, x.fn, x.em, co.id
  FROM (VALUES
    ('Marco Rossi', 'marco.rossi@example.com', 'IT'),
    ('Sophie Martin', 'sophie.martin@example.com', 'FR'),
    ('Jan Kowalski', 'jan.kowalski@example.com', 'PL'),
    ('Emma Schmidt', 'emma.schmidt@example.com', 'DE'),
    ('Liam Johnson', 'liam.johnson@example.com', 'US'),
    ('Olivia Williams', 'olivia.williams@example.com', 'GB'),
    ('Carlos Garcia', 'carlos.garcia@example.com', 'ES'),
    ('Anna Mueller', 'anna.mueller@example.com', 'DE'),
    ('Thomas Dubois', 'thomas.dubois@example.com', 'FR'),
    ('Sara Bianchi', 'sara.bianchi@example.com', 'IT'),
    ('Piotr Nowak', 'piotr.nowak@example.com', 'PL'),
    ('Lucas De Vries', 'lucas.devries@example.com', 'NL'),
    ('Mia Thompson', 'mia.thompson@example.com', 'CA'),
    ('Noah Anderson', 'noah.anderson@example.com', 'US'),
    ('Isabella Fernandez', 'isabella.fernandez@example.com', 'ES'),
    ('Luca Romano', 'luca.romano@example.com', 'IT'),
    ('Chloe Bernard', 'chloe.bernard@example.com', 'FR'),
    ('Jakub Wisnewski', 'jakub.wisnewski@example.com', 'PL'),
    ('Hannah Clarke', 'hannah.clarke@example.com', 'GB'),
    ('Finn Hansen', 'finn.hansen@example.com', 'DE'),
    ('Emily Walker', 'emily.walker@example.com', 'CA'),
    ('James Wilson', 'james.wilson@example.com', 'US'),
    ('Laura Moreau', 'laura.moreau@example.com', 'FR'),
    ('David Van Den Berg', 'david.vandenberg@example.com', 'NL'),
    ('Sofia Greco', 'sofia.greco@example.com', 'IT'),
    ('Aleksandra Wojcik', 'aleksandra.wojcik@example.com', 'PL'),
    ('Ben Taylor', 'ben.taylor@example.com', 'GB'),
    ('Leon Wagner', 'leon.wagner@example.com', 'DE'),
    ('Valentina Lopez', 'valentina.lopez@example.com', 'ES'),
    ('Ryan Mitchell', 'ryan.mitchell@example.com', 'CA'),
    ('Elena Conti', 'elena.conti@example.com', 'IT'),
    ('Marie Leroy', 'marie.leroy@example.com', 'FR'),
    ('Dawid Lewandowski', 'dawid.lewandowski@example.com', 'PL'),
    ('Grace Robinson', 'grace.robinson@example.com', 'GB'),
    ('Max Becker', 'max.becker@example.com', 'DE')
  ) AS x(fn, em, iso)
  JOIN countries co ON co.iso_code = x.iso;

  INSERT INTO organization_members (organization_id, user_id, role)
  SELECT v_org, id, 'manager'::user_role FROM users WHERE username = 'testuser';

  PERFORM seed_org_demo(v_org, CURRENT_DATE - 364, CURRENT_DATE);
END $$;

DO $$
DECLARE v_org INTEGER;
BEGIN
  INSERT INTO organizations (name, slug, plan) VALUES ('PixelForge', 'pixelforge', 'Free') RETURNING id INTO v_org;
  PERFORM seed_org_dimensions(v_org);

  INSERT INTO products (organization_id, category_id, name, base_price)
  SELECT v_org, c.id, p.name, p.price
  FROM (VALUES
    ('electronics', 'AMD Ryzen 9 7950X Processor', 699.00),
    ('electronics', 'Intel Core i9-14900K Processor', 589.00),
    ('electronics', 'NVIDIA GeForce RTX 4080 GPU', 1199.00),
    ('electronics', 'AMD Radeon RX 7900 XTX GPU', 999.00),
    ('electronics', 'Samsung 980 Pro 2TB NVMe SSD', 179.00),
    ('electronics', 'Corsair Vengeance 32GB DDR5 RAM', 149.00),
    ('electronics', 'ASUS ROG STRIX Z790 Motherboard', 399.00),
    ('electronics', 'Seasonic Focus GX 850W PSU', 139.00),
    ('electronics', 'Noctua NH-D15 CPU Cooler', 99.00),
    ('electronics', 'NZXT Kraken 360 AIO Liquid Cooler', 179.00),
    ('electronics', 'LG 27GP850-B 27in 1440p Monitor', 349.00),
    ('electronics', 'Samsung Odyssey G7 32in Monitor', 499.00),
    ('electronics', 'Logitech G Pro X Superlight Mouse', 159.00),
    ('electronics', 'Razer BlackWidow V3 Mechanical Keyboard', 139.00),
    ('electronics', 'HyperX Cloud Alpha Headset', 99.00),
    ('electronics', 'SteelSeries Arctis Nova Pro Headset', 249.00),
    ('electronics', 'Elgato Stream Deck MK.2', 149.00),
    ('electronics', 'AVerMedia Live Gamer Portable 2 Capture Card', 119.00),
    ('electronics', 'Logitech C922 Pro Webcam', 89.00),
    ('electronics', 'Blue Yeti USB Microphone', 129.00),
    ('home-office', 'Secretlab TITAN Evo Gaming Chair', 549.00),
    ('home-office', 'DXRacer Formula Series Gaming Chair', 349.00),
    ('home-office', 'LIAN LI Lancool 216 Mid-Tower Case', 119.00),
    ('home-office', 'Fractal Design Meshify 2 Case', 139.00),
    ('home-office', 'Custom RGB LED Strip Kit 5m', 34.99),
    ('home-office', 'Cable Sleeving Kit 24pin ATX', 19.99),
    ('home-office', 'Anti-Static Wrist Strap', 9.99),
    ('home-office', 'PC Building Tool Kit 12-piece', 29.99),
    ('home-office', 'Monitor Light Bar LED', 49.99),
    ('home-office', 'Headphone Stand with USB Hub', 39.99),
    ('fashion', 'PixelForge Logo Hoodie', 59.00),
    ('fashion', 'Esports Team Jersey Short Sleeve', 39.00),
    ('fashion', 'Gaming Compression Gloves', 24.99),
    ('fashion', 'Anti-Glare Gaming Glasses', 49.00),
    ('fashion', 'PixelForge Snapback Cap', 29.00),
    ('other', 'PixelForge Gift Card 25', 25.00),
    ('other', 'Thermal Paste Tube 4g', 9.99),
    ('other', 'Microfiber Screen Cleaning Kit', 12.99),
    ('other', 'Cable Tie Velcro Pack 50pcs', 7.99),
    ('other', 'PCB Display Stand Acrylic', 17.99)
  ) AS p(cat, name, price)
  JOIN categories c ON c.organization_id = v_org AND c.slug = p.cat;

  INSERT INTO customers (organization_id, full_name, email, country_id)
  SELECT v_org, x.fn, x.em, co.id
  FROM (VALUES
    ('Tyler Brooks', 'tyler.brooks@example.com', 'US'),
    ('Kevin Schulz', 'kevin.schulz@example.com', 'DE'),
    ('Bartosz Kaczmarek', 'bartosz.kaczmarek@example.com', 'PL'),
    ('Dylan Hughes', 'dylan.hughes@example.com', 'GB'),
    ('Ethan Tremblay', 'ethan.tremblay@example.com', 'CA'),
    ('Lucas Smit', 'lucas.smit@example.com', 'NL'),
    ('Pierre Girard', 'pierre.girard@example.com', 'FR'),
    ('Matteo Ferrara', 'matteo.ferrara@example.com', 'IT'),
    ('Alejandro Ruiz', 'alejandro.ruiz@example.com', 'ES'),
    ('Krzysztof Zielinski', 'krzysztof.zielinski@example.com', 'PL'),
    ('Jordan Campbell', 'jordan.campbell@example.com', 'US'),
    ('Tobias Fischer', 'tobias.fischer@example.com', 'DE'),
    ('Callum Stewart', 'callum.stewart@example.com', 'GB'),
    ('Nathan Bouchard', 'nathan.bouchard@example.com', 'CA'),
    ('Sander Bakker', 'sander.bakker@example.com', 'NL'),
    ('Antoine Mercier', 'antoine.mercier@example.com', 'FR'),
    ('Riccardo Esposito', 'riccardo.esposito@example.com', 'IT'),
    ('Diego Martinez', 'diego.martinez@example.com', 'ES'),
    ('Michal Duda', 'michal.duda@example.com', 'PL'),
    ('Austin Reed', 'austin.reed@example.com', 'US'),
    ('Lukas Hoffmann', 'lukas.hoffmann@example.com', 'DE'),
    ('Oliver Evans', 'oliver.evans@example.com', 'GB'),
    ('Zach Lavoie', 'zach.lavoie@example.com', 'CA'),
    ('Bram Visser', 'bram.visser@example.com', 'NL'),
    ('Hugo Lambert', 'hugo.lambert@example.com', 'FR'),
    ('Gianluca Bruno', 'gianluca.bruno@example.com', 'IT'),
    ('Pablo Sanchez', 'pablo.sanchez@example.com', 'ES'),
    ('Radoslaw Pawlak', 'radoslaw.pawlak@example.com', 'PL'),
    ('Mason Carter', 'mason.carter@example.com', 'US'),
    ('Felix Richter', 'felix.richter@example.com', 'DE'),
    ('Aiden Murphy', 'aiden.murphy@example.com', 'GB'),
    ('Cole Gagnon', 'cole.gagnon@example.com', 'CA'),
    ('Jesse Janssen', 'jesse.janssen@example.com', 'NL'),
    ('Clement Roux', 'clement.roux@example.com', 'FR'),
    ('Simone Marini', 'simone.marini@example.com', 'IT')
  ) AS x(fn, em, iso)
  JOIN countries co ON co.iso_code = x.iso;

  INSERT INTO organization_members (organization_id, user_id, role)
  SELECT v_org, id, 'analyst'::user_role FROM users WHERE username = 'testuser';

  PERFORM seed_org_demo(v_org, CURRENT_DATE - 364, CURRENT_DATE);
END $$;
DO $$
DECLARE v_org INTEGER;
BEGIN
  INSERT INTO organizations (name, slug, plan) VALUES ('NordicGoods', 'nordicgoods', 'Pro Workspace') RETURNING id INTO v_org;
  PERFORM seed_org_dimensions(v_org);

  INSERT INTO products (organization_id, category_id, name, base_price)
  SELECT v_org, c.id, p.name, p.price
  FROM (VALUES
    ('electronics', 'Marshall Emberton II Speaker', 169.99),
    ('electronics', 'Bose SoundLink Flex', 149.00),
    ('electronics', 'Anker PowerCore 26800 Portable Charger', 59.99),
    ('electronics', 'Belkin 3-in-1 Wireless Charger', 79.99),
    ('electronics', 'Sony WH-1000XM5 Headphones', 349.99),
    ('electronics', 'Logitech MX Master 3S Mouse', 99.99),
    ('electronics', 'Philips Hue White Starter Kit', 69.99),
    ('electronics', 'Kindle Paperwhite 16GB', 139.99),
    ('electronics', 'Tile Mate 4-Pack Tracker', 49.99),
    ('home-office', 'Muuto Unfold Table Lamp', 189.00),
    ('home-office', 'HAY About A Chair AAC22', 249.00),
    ('home-office', 'Ferm Living Plant Box', 79.00),
    ('home-office', 'Iittala Teema Dinner Set 16pc', 159.00),
    ('home-office', 'Normann Copenhagen Himmee Lamp', 219.00),
    ('home-office', 'String Pocket Shelf System', 139.00),
    ('home-office', 'Hay Dot Cushion Set of 2', 59.00),
    ('home-office', 'Broste Copenhagen Glass Vase', 39.00),
    ('home-office', 'Eva Solo Nordic Kitchen Knife Set', 129.00),
    ('home-office', 'Menu Hydro Carafe 1L', 49.00),
    ('fashion', 'Fjallraven Kanken Classic Backpack', 95.00),
    ('fashion', 'Fjallraven Ovik Fleece Jacket', 130.00),
    ('fashion', 'Norse Projects Rollo Canvas Tote', 55.00),
    ('fashion', 'Nudie Jeans Lean Dean Slim', 149.00),
    ('fashion', 'Acne Studios Wool Scarf', 170.00),
    ('fashion', 'Stutterheim Raincoat Stockholm', 295.00),
    ('fashion', 'Veja Esplar Low Sneakers', 120.00),
    ('fashion', 'Peak Performance Frost Ski Jacket', 399.00),
    ('fashion', 'Sandqvist Bernt Backpack', 185.00),
    ('other', 'Stanley Quencher 40oz Tumbler', 45.00),
    ('other', 'Klean Kanteen Classic 27oz', 34.99),
    ('other', 'Hydro Flask 32oz Wide Mouth', 49.95),
    ('other', 'Yeti Rambler 20oz Tumbler', 35.00),
    ('other', 'Fellow Stagg EKG Kettle', 165.00),
    ('other', 'Aeropress Go Coffee Maker', 39.99),
    ('other', 'Nordic Ware Platinum Bundt Pan', 29.99),
    ('other', 'Stelton Emma Thermos 1L', 89.00),
    ('other', 'Sagaform BBQ Grill Brush', 24.99),
    ('other', 'Rosti Margrethe Mixing Bowl Set', 44.00),
    ('other', 'Duralex Picardie Glass Set of 6', 22.00),
    ('other', 'Bodum Pour Over Coffee Maker', 19.99)
  ) AS p(cat, name, price)
  JOIN categories c ON c.organization_id = v_org AND c.slug = p.cat;

  INSERT INTO customers (organization_id, full_name, email, country_id)
  SELECT v_org, x.fn, x.em, co.id
  FROM (VALUES
    ('Lars Hansen', 'lars.hansen@example.com', 'NL'),
    ('Emma Wilson', 'emma.wilson@example.com', 'GB'),
    ('Sven Mueller', 'sven.mueller@example.com', 'DE'),
    ('Anna Lindqvist', 'anna.lindqvist@example.com', 'NL'),
    ('Pieter Janssen', 'pieter.janssen@example.com', 'NL'),
    ('Sophie Martin', 'sophie.martin@example.com', 'FR'),
    ('James Carter', 'james.carter@example.com', 'US'),
    ('Maja Kowalski', 'maja.kowalski@example.com', 'PL'),
    ('Luca Rossi', 'luca.rossi@example.com', 'IT'),
    ('Clara Dubois', 'clara.dubois@example.com', 'FR'),
    ('Tom Becker', 'tom.becker@example.com', 'DE'),
    ('Olivia Brown', 'olivia.brown@example.com', 'GB'),
    ('Noah Davis', 'noah.davis@example.com', 'US'),
    ('Ingrid Berg', 'ingrid.berg@example.com', 'NL'),
    ('Carlos Garcia', 'carlos.garcia@example.com', 'ES'),
    ('Maria Lopez', 'maria.lopez@example.com', 'ES'),
    ('Jakub Nowak', 'jakub.nowak@example.com', 'PL'),
    ('Franziska Braun', 'franziska.braun@example.com', 'DE'),
    ('William Thompson', 'william.thompson@example.com', 'CA'),
    ('Chloe Petit', 'chloe.petit@example.com', 'FR'),
    ('Marco Ferrari', 'marco.ferrari@example.com', 'IT'),
    ('Hannah Schmidt', 'hannah.schmidt@example.com', 'DE'),
    ('Ethan Walker', 'ethan.walker@example.com', 'US'),
    ('Lucie Bernard', 'lucie.bernard@example.com', 'FR'),
    ('Piotr Wieczorek', 'piotr.wieczorek@example.com', 'PL'),
    ('Charlotte Evans', 'charlotte.evans@example.com', 'GB'),
    ('Diego Fernandez', 'diego.fernandez@example.com', 'ES'),
    ('Katarzyna Wisniewska', 'katarzyna.wisniewska@example.com', 'PL'),
    ('Leon Fischer', 'leon.fischer@example.com', 'DE'),
    ('Isabella Conti', 'isabella.conti@example.com', 'IT'),
    ('Lucas Tremblay', 'lucas.tremblay@example.com', 'CA'),
    ('Amelia Hughes', 'amelia.hughes@example.com', 'GB'),
    ('Rafael Moreno', 'rafael.moreno@example.com', 'ES'),
    ('Zofia Kaminska', 'zofia.kaminska@example.com', 'PL'),
    ('Oliver Green', 'oliver.green@example.com', 'US')
  ) AS x(fn, em, iso)
  JOIN countries co ON co.iso_code = x.iso;

  INSERT INTO organization_members (organization_id, user_id, role)
  SELECT v_org, id, 'analyst'::user_role FROM users WHERE username = 'testuser';

  PERFORM seed_org_demo(v_org, CURRENT_DATE - 364, CURRENT_DATE);
END $$;

DO $$
DECLARE v_org INTEGER;
BEGIN
  INSERT INTO organizations (name, slug, plan) VALUES ('UrbanCarry', 'urbancarry', 'Free') RETURNING id INTO v_org;
  PERFORM seed_org_dimensions(v_org);

  INSERT INTO products (organization_id, category_id, name, base_price)
  SELECT v_org, c.id, p.name, p.price
  FROM (VALUES
    ('electronics', 'Anker USB-C Travel Hub 7-in-1', 49.99),
    ('electronics', 'Tile Slim Card Tracker', 34.99),
    ('electronics', 'Baseus 65W GaN Travel Charger', 39.99),
    ('electronics', 'Peak Design Capture Clip V3', 79.95),
    ('electronics', 'Satechi Slim Travel Cable Pouch', 29.99),
    ('electronics', 'Nimble Wally Pro Cable Organizer', 24.99),
    ('electronics', 'Zendure SuperMini 10000mAh', 54.99),
    ('electronics', 'Twelve South AirFly Pro', 44.99),
    ('home-office', 'Bellroy Work Folio A4 Notebook Case', 119.00),
    ('home-office', 'Moleskine Classic Notebook Large', 22.00),
    ('home-office', 'Troika Pen Set Travel Edition', 19.99),
    ('home-office', 'Peak Design Laptop Sleeve 13in', 89.00),
    ('home-office', 'Bellroy Laptop Sleeve 14in', 75.00),
    ('home-office', 'Secrid Slimwallet Aluminium', 85.00),
    ('home-office', 'Bellroy Hide and Seek Wallet', 95.00),
    ('fashion', 'Osprey Farpoint 40 Travel Pack', 200.00),
    ('fashion', 'Aer Travel Pack 3 Small', 185.00),
    ('fashion', 'Cotopaxi Allpa 35L Backpack', 175.00),
    ('fashion', 'Patagonia Black Hole Duffel 55L', 169.00),
    ('fashion', 'Herschel Supply Co Novel Duffel', 110.00),
    ('fashion', 'Topo Designs Global Briefcase', 129.00),
    ('fashion', 'Fjallraven Splitpack 35', 225.00),
    ('fashion', 'Db Journey Hugger Backpack 25L', 195.00),
    ('fashion', 'Peak Design Everyday Backpack 20L', 299.95),
    ('fashion', 'Tortuga Setout Laptop Backpack 45L', 229.00),
    ('fashion', 'Chrome Industries Niko 3.0 Camera Bag', 160.00),
    ('fashion', 'Wandrd Prvke 21 Backpack', 189.00),
    ('other', 'Eagle Creek Pack-It Specter Cube Set', 45.00),
    ('other', 'Osprey Packing Cubes Set of 3', 39.95),
    ('other', 'Horizn Studios Packing Cube Set', 49.00),
    ('other', 'Samsonite Luggage Scale Digital', 14.99),
    ('other', 'Lewis N Clark Luggage Tags Set of 2', 9.99),
    ('other', 'Pacsafe Paclite Plus Luggage Cover M', 29.95),
    ('other', 'AmazonBasics Hardside Carry-On 20in', 79.99),
    ('other', 'Samsonite Eco-Nu Spinner 20in', 139.00),
    ('other', 'Horizn Studios M5 Cabin Suitcase', 495.00),
    ('other', 'Away The Carry-On Bigger', 295.00),
    ('other', 'Travelpro Maxlite 5 Carry-On', 129.99),
    ('other', 'Compression Travel Pillow Ultralight', 29.95),
    ('other', 'Sea to Summit Aeros Premium Pillow', 64.95),
    ('other', 'Matador FlatPak Soap Case', 19.95)
  ) AS p(cat, name, price)
  JOIN categories c ON c.organization_id = v_org AND c.slug = p.cat;

  INSERT INTO customers (organization_id, full_name, email, country_id)
  SELECT v_org, x.fn, x.em, co.id
  FROM (VALUES
    ('Alex Turner', 'alex.turner@example.com', 'GB'),
    ('Nina Schmidt', 'nina.schmidt@example.com', 'DE'),
    ('Ryan Mitchell', 'ryan.mitchell@example.com', 'US'),
    ('Sara Johansson', 'sara.johansson@example.com', 'NL'),
    ('Ben Clarke', 'ben.clarke@example.com', 'CA'),
    ('Marta Kowalczyk', 'marta.kowalczyk@example.com', 'PL'),
    ('Antoine Lefevre', 'antoine.lefevre@example.com', 'FR'),
    ('Giulia Marino', 'giulia.marino@example.com', 'IT'),
    ('Erik Hoffman', 'erik.hoffman@example.com', 'DE'),
    ('Laura Sanchez', 'laura.sanchez@example.com', 'ES'),
    ('Jake Robinson', 'jake.robinson@example.com', 'US'),
    ('Nadia Rousseau', 'nadia.rousseau@example.com', 'FR'),
    ('Finn Larsen', 'finn.larsen@example.com', 'NL'),
    ('Priya Sharma', 'priya.sharma@example.com', 'GB'),
    ('Tom Vandenberg', 'tom.vandenberg@example.com', 'NL'),
    ('Elena Bianchi', 'elena.bianchi@example.com', 'IT'),
    ('Chris Patterson', 'chris.patterson@example.com', 'CA'),
    ('Aleksandra Wrobel', 'aleksandra.wrobel@example.com', 'PL'),
    ('Maxime Dupont', 'maxime.dupont@example.com', 'FR'),
    ('Hannah Cole', 'hannah.cole@example.com', 'GB'),
    ('Luis Ortega', 'luis.ortega@example.com', 'ES'),
    ('Markus Weber', 'markus.weber@example.com', 'DE'),
    ('Abby Foster', 'abby.foster@example.com', 'US'),
    ('Pawel Czerwinski', 'pawel.czerwinski@example.com', 'PL'),
    ('Valeria Greco', 'valeria.greco@example.com', 'IT'),
    ('Sean Murphy', 'sean.murphy@example.com', 'CA'),
    ('Isabelle Morin', 'isabelle.morin@example.com', 'FR'),
    ('Daniel Kruger', 'daniel.kruger@example.com', 'DE'),
    ('Sophia Reed', 'sophia.reed@example.com', 'US'),
    ('Bart Dekker', 'bart.dekker@example.com', 'NL'),
    ('Monika Szymanska', 'monika.szymanska@example.com', 'PL'),
    ('Pablo Martinez', 'pablo.martinez@example.com', 'ES'),
    ('Cecilia Fontana', 'cecilia.fontana@example.com', 'IT'),
    ('Owen Harris', 'owen.harris@example.com', 'GB'),
    ('Tanja Bergmann', 'tanja.bergmann@example.com', 'DE')
  ) AS x(fn, em, iso)
  JOIN countries co ON co.iso_code = x.iso;

  INSERT INTO organization_members (organization_id, user_id, role)
  SELECT v_org, id, 'viewer'::user_role FROM users WHERE username = 'testuser';

  PERFORM seed_org_demo(v_org, CURRENT_DATE - 364, CURRENT_DATE);
END $$;
DO $$
DECLARE v_org INTEGER;
BEGIN
  INSERT INTO organizations (name, slug, plan) VALUES ('SoundHaus', 'soundhaus', 'Premium Workspace') RETURNING id INTO v_org;
  PERFORM seed_org_dimensions(v_org);

  INSERT INTO products (organization_id, category_id, name, base_price)
  SELECT v_org, c.id, p.name, p.price
  FROM (VALUES
    ('electronics', 'Sonos Era 100 Wireless Speaker', 249.00),
    ('electronics', 'Sonos Arc Premium Soundbar', 899.00),
    ('electronics', 'Bose QuietComfort 45 Headphones', 329.00),
    ('electronics', 'Bose SoundLink Flex Bluetooth Speaker', 149.00),
    ('electronics', 'Sony WH-1000XM5 Headphones', 349.00),
    ('electronics', 'Sony XM4 In-Ear Noise Cancelling Buds', 249.00),
    ('electronics', 'Sennheiser HD 660S2 Open-Back Headphones', 599.00),
    ('electronics', 'Audio-Technica AT-LP120X Turntable', 299.00),
    ('electronics', 'Denon AVR-X1800H AV Receiver', 549.00),
    ('electronics', 'Yamaha RX-V4A Network Receiver', 449.00),
    ('electronics', 'KEF Q150 Bookshelf Speaker Pair', 599.00),
    ('electronics', 'Q Acoustics 3020i Bookshelf Speakers', 299.00),
    ('electronics', 'Polk Audio Reserve R200 Bookshelf Pair', 499.00),
    ('electronics', 'Elac Debut 2.0 B6.2 Speaker Pair', 349.00),
    ('home-office', 'Vogels NEXT 7345 Full-Motion TV Mount', 129.99),
    ('home-office', 'Vogels TMS 1000 Monitor Sound System', 199.00),
    ('home-office', 'Flexson Wall Mount for Sonos Era 100', 49.99),
    ('home-office', 'Flexson Floorstand for Sonos Era 300', 89.99),
    ('home-office', 'AVF Multi-Position TV Stand 55 inch', 79.99),
    ('home-office', 'Sanus Swivel TV Base 40-65 inch', 59.99),
    ('home-office', 'Amazon Echo Studio Smart Speaker', 199.99),
    ('home-office', 'Amazon Echo Show 10 Smart Display', 249.99),
    ('home-office', 'Logitech Z623 2.1 Speaker System', 149.99),
    ('home-office', 'Audioengine A2 Plus Desktop Speakers', 269.00),
    ('fashion', 'Bose Frames Tenor Audio Sunglasses', 199.00),
    ('fashion', 'Bose Frames Soprano Audio Sunglasses', 199.00),
    ('fashion', 'Razer Anzu Smart Glasses Round Frame', 159.99),
    ('fashion', 'JLab JBuds Frames Audio Glasses', 49.99),
    ('fashion', 'Shure AONIC 215 Sound Isolating Earphones', 99.00),
    ('fashion', 'Jabra Evolve2 55 Wireless Headset', 449.00),
    ('other', 'Vinyl Record Cleaning Kit Deluxe', 34.99),
    ('other', 'Audio-Technica AT617a Record Cleaner', 14.99),
    ('other', 'iFi Audio iPower2 Noise Eliminator', 69.99),
    ('other', 'FiiO BTR5 2021 Bluetooth DAC Amplifier', 129.99),
    ('other', 'Chord Mojo 2 DAC Headphone Amplifier', 649.00),
    ('other', 'Kanto YU Bluetooth Bookshelf Speaker Stand', 79.99),
    ('other', 'Mogami Gold Studio XLR Cable 6ft', 39.99),
    ('other', 'Belkin SoundForm Connect Audio Adapter', 39.99),
    ('other', 'Anker Soundcore Life Q30 Headphones', 79.99),
    ('other', 'TechniSat DIGITRADIO 1 DAB Radio', 59.99)
  ) AS p(cat, name, price)
  JOIN categories c ON c.organization_id = v_org AND c.slug = p.cat;

  INSERT INTO customers (organization_id, full_name, email, country_id)
  SELECT v_org, x.fn, x.em, co.id
  FROM (VALUES
    ('Diego Garcia', 'diego.garcia@example.com', 'ES'),
    ('Liam Murphy', 'liam.murphy@example.com', 'CA'),
    ('Anna Mueller', 'anna.mueller@example.com', 'DE'),
    ('Hans Becker', 'hans.becker@example.com', 'DE'),
    ('Sophie Lambert', 'sophie.lambert@example.com', 'FR'),
    ('James Wilson', 'james.wilson@example.com', 'GB'),
    ('Emma Thompson', 'emma.thompson@example.com', 'GB'),
    ('Marco Rossi', 'marco.rossi@example.com', 'IT'),
    ('Laura Bianchi', 'laura.bianchi@example.com', 'IT'),
    ('Piotr Kowalski', 'piotr.kowalski@example.com', 'PL'),
    ('Katarzyna Nowak', 'katarzyna.nowak@example.com', 'PL'),
    ('Jan Janssen', 'jan.janssen@example.com', 'NL'),
    ('Lisa van den Berg', 'lisa.vandenberg@example.com', 'NL'),
    ('Carlos Fernandez', 'carlos.fernandez@example.com', 'ES'),
    ('Elena Moreno', 'elena.moreno@example.com', 'ES'),
    ('Michael Brown', 'michael.brown@example.com', 'US'),
    ('Jessica Davis', 'jessica.davis@example.com', 'US'),
    ('Ryan Miller', 'ryan.miller@example.com', 'US'),
    ('Sarah Johnson', 'sarah.johnson@example.com', 'US'),
    ('Thomas Martin', 'thomas.martin@example.com', 'FR'),
    ('Isabelle Dubois', 'isabelle.dubois@example.com', 'FR'),
    ('Stefan Fischer', 'stefan.fischer@example.com', 'DE'),
    ('Petra Hoffmann', 'petra.hoffmann@example.com', 'DE'),
    ('Oliver Smith', 'oliver.smith@example.com', 'GB'),
    ('Charlotte Evans', 'charlotte.evans@example.com', 'GB'),
    ('Wojciech Wieczorek', 'wojciech.wieczorek@example.com', 'PL'),
    ('Magdalena Wrobel', 'magdalena.wrobel@example.com', 'PL'),
    ('Luca Ferrari', 'luca.ferrari@example.com', 'IT'),
    ('Giulia Esposito', 'giulia.esposito@example.com', 'IT'),
    ('Noah Tremblay', 'noah.tremblay@example.com', 'CA'),
    ('Olivia Bouchard', 'olivia.bouchard@example.com', 'CA'),
    ('Pieter de Vries', 'pieter.devries@example.com', 'NL'),
    ('Maria Garcia Lopez', 'maria.garcialopez@example.com', 'ES'),
    ('Kevin Taylor', 'kevin.taylor@example.com', 'US'),
    ('Nathalie Clement', 'nathalie.clement@example.com', 'FR')
  ) AS x(fn, em, iso)
  JOIN countries co ON co.iso_code = x.iso;

  INSERT INTO organization_members (organization_id, user_id, role)
  SELECT v_org, id, 'manager'::user_role FROM users WHERE username = 'testuser';

  PERFORM seed_org_demo(v_org, CURRENT_DATE - 364, CURRENT_DATE);
END $$;

DO $$
DECLARE v_org INTEGER;
BEGIN
  INSERT INTO organizations (name, slug, plan) VALUES ('PeakForm', 'peakform', 'Pro Workspace') RETURNING id INTO v_org;
  PERFORM seed_org_dimensions(v_org);

  INSERT INTO products (organization_id, category_id, name, base_price)
  SELECT v_org, c.id, p.name, p.price
  FROM (VALUES
    ('electronics', 'Garmin Forerunner 965 GPS Watch', 599.99),
    ('electronics', 'Garmin Edge 540 Cycling Computer', 399.99),
    ('electronics', 'Polar Vantage V3 Multisport Watch', 649.00),
    ('electronics', 'Wahoo ELEMNT BOLT GPS Bike Computer', 279.99),
    ('electronics', 'Suunto 9 Peak Pro GPS Watch', 549.00),
    ('electronics', 'Coros APEX 2 Pro GPS Watch', 499.99),
    ('electronics', 'Fitbit Charge 6 Fitness Tracker', 159.99),
    ('electronics', 'Withings Body Comp Smart Scale', 199.95),
    ('electronics', 'Therabody Theragun Pro Massage Device', 599.00),
    ('electronics', 'Hyperice Hypervolt 2 Pro Massager', 399.00),
    ('home-office', 'NordicTrack Commercial 1750 Treadmill', 1799.00),
    ('home-office', 'Bowflex SelectTech 552 Adjustable Dumbbells', 429.00),
    ('home-office', 'Rogue Monster Lite Pull-Up Rig', 349.00),
    ('home-office', 'Cap Barbell Olympic Weight Set 300lb', 299.99),
    ('home-office', 'Concept2 RowErg Rowing Machine', 1199.00),
    ('home-office', 'Assault AirBike Classic', 699.00),
    ('home-office', 'TRX All-in-One Suspension Trainer', 149.95),
    ('home-office', 'Kettlebell Kings 35lb Competition Bell', 89.99),
    ('home-office', 'Rogue Fitness Foam Roller 36 inch', 49.99),
    ('home-office', 'Yoga Design Lab Combo Mat 5.5mm', 89.99),
    ('fashion', 'Nike Dri-FIT ADV Techknit Running Shirt', 89.99),
    ('fashion', 'Adidas Terrex Agravic Trail Running Shorts', 59.99),
    ('fashion', 'Under Armour RUSH Compression Leggings', 74.99),
    ('fashion', 'Patagonia Houdini Wind Jacket', 129.00),
    ('fashion', 'Arc teryx Norvan SL Hoody Trail Jacket', 199.00),
    ('fashion', 'Salomon Speedcross 6 Trail Running Shoes', 139.99),
    ('fashion', 'Hoka Speedgoat 5 Trail Running Shoes', 155.00),
    ('fashion', 'Brooks Ghost 15 Road Running Shoes', 139.95),
    ('fashion', 'Oakley Sutro Lite Cycling Sunglasses', 169.00),
    ('fashion', 'Craft ADV Endur Bib Shorts Cycling', 149.99),
    ('other', 'Maurten Gel 100 Sport Nutrition Pack of 12', 59.99),
    ('other', 'SiS Beta Fuel Energy Gel Box of 30', 79.99),
    ('other', 'Hydrapak Softflask 500ml', 19.99),
    ('other', 'Nathan SpeedDraw Plus Insulated Flask', 34.99),
    ('other', 'Black Diamond Distance Z Trekking Poles', 149.95),
    ('other', 'Osprey Duro 6 Trail Running Vest', 139.99),
    ('other', 'Trigger Point GRID Foam Roller', 39.99),
    ('other', 'KT Tape Pro Elastic Athletic Tape', 19.99),
    ('other', 'Clif Bar Energy Bar Variety Pack 24ct', 49.99),
    ('other', 'Nalgene Tritan Wide Mouth Water Bottle 32oz', 14.99)
  ) AS p(cat, name, price)
  JOIN categories c ON c.organization_id = v_org AND c.slug = p.cat;

  INSERT INTO customers (organization_id, full_name, email, country_id)
  SELECT v_org, x.fn, x.em, co.id
  FROM (VALUES
    ('Alex Turner', 'alex.turner@example.com', 'GB'),
    ('Marta Kowalczyk', 'marta.kowalczyk@example.com', 'PL'),
    ('Ben Schmidt', 'ben.schmidt@example.com', 'DE'),
    ('Claire Fontaine', 'claire.fontaine@example.com', 'FR'),
    ('David Martinez', 'david.martinez@example.com', 'ES'),
    ('Emily Clark', 'emily.clark@example.com', 'US'),
    ('Frank Ricci', 'frank.ricci@example.com', 'IT'),
    ('Grace Nakamura', 'grace.nakamura@example.com', 'CA'),
    ('Henrik Krause', 'henrik.krause@example.com', 'DE'),
    ('Irene Morel', 'irene.morel@example.com', 'FR'),
    ('Jack Robinson', 'jack.robinson@example.com', 'US'),
    ('Karen van Dijk', 'karen.vandijk@example.com', 'NL'),
    ('Luis Sanchez', 'luis.sanchez@example.com', 'ES'),
    ('Maria Conti', 'maria.conti@example.com', 'IT'),
    ('Nathan Bergstrom', 'nathan.bergstrom@example.com', 'CA'),
    ('Olivia Patel', 'olivia.patel@example.com', 'GB'),
    ('Patrick Dupont', 'patrick.dupont@example.com', 'FR'),
    ('Quinn Foster', 'quinn.foster@example.com', 'US'),
    ('Rachel Hughes', 'rachel.hughes@example.com', 'GB'),
    ('Sven Larssen', 'sven.larssen@example.com', 'DE'),
    ('Tanja Vogel', 'tanja.vogel@example.com', 'DE'),
    ('Umberto Greco', 'umberto.greco@example.com', 'IT'),
    ('Valeria Russo', 'valeria.russo@example.com', 'IT'),
    ('Willem Smit', 'willem.smit@example.com', 'NL'),
    ('Xenia de Boer', 'xenia.deboer@example.com', 'NL'),
    ('Yannick Girard', 'yannick.girard@example.com', 'FR'),
    ('Zofia Wisniewska', 'zofia.wisniewska@example.com', 'PL'),
    ('Adam Wojcik', 'adam.wojcik@example.com', 'PL'),
    ('Brianna Scott', 'brianna.scott@example.com', 'US'),
    ('Connor Walsh', 'connor.walsh@example.com', 'CA'),
    ('Diana Cruz', 'diana.cruz@example.com', 'ES'),
    ('Ethan Brooks', 'ethan.brooks@example.com', 'US'),
    ('Fiona MacLeod', 'fiona.macleod@example.com', 'GB'),
    ('Giovanni Ferrara', 'giovanni.ferrara@example.com', 'IT'),
    ('Hanna Schulz', 'hanna.schulz@example.com', 'DE')
  ) AS x(fn, em, iso)
  JOIN countries co ON co.iso_code = x.iso;

  INSERT INTO organization_members (organization_id, user_id, role)
  SELECT v_org, id, 'admin'::user_role FROM users WHERE username = 'testuser';

  PERFORM seed_org_demo(v_org, CURRENT_DATE - 364, CURRENT_DATE);
END $$;
