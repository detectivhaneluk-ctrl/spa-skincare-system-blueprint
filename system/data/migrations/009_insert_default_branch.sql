INSERT INTO branches (name, code) SELECT 'Default', 'DEFAULT' WHERE NOT EXISTS (SELECT 1 FROM branches LIMIT 1);
