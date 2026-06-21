-- Bootstrap script executed by the official postgres image on first boot.
-- Add your CREATE EXTENSION / GRANT statements here; this file is committed
-- because it contains no secrets and only idempotent schema setup.

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
