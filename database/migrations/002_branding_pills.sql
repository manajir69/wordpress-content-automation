-- Reference only: the app automatically adds this column if missing.
-- Do not run again if the column already exists; do not import 001_initial.sql.
ALTER TABLE blog_settings ADD COLUMN blog_text_background_opacity TINYINT UNSIGNED NOT NULL DEFAULT 35;
