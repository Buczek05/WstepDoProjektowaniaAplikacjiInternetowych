CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,

    email VARCHAR(255) UNIQUE NOT NULL,
    password TEXT NOT NULL,

    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,

    is_active BOOLEAN DEFAULT TRUE
);

-- Hasło testowe dla obu kont: "password" (bcrypt)
INSERT INTO users (username, email, password) VALUES
('admin', 'admin@example.com', '$2b$10$HL5Ng/aNOwz/hsKVVx0xoua00JCd2yzNeNii7/1NYd1JpQCj7oCuS'),
('jan_kowalski', 'jan@example.com', '$2b$10$HL5Ng/aNOwz/hsKVVx0xoua00JCd2yzNeNii7/1NYd1JpQCj7oCuS');
