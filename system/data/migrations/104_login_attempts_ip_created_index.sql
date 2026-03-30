-- LOGIN-THROTTLE-CANONICALIZATION-16: optional read path support for IP-scoped audits / tooling (Layer A stores ip_address per row).
ALTER TABLE login_attempts
    ADD INDEX idx_login_attempts_ip_created (ip_address, created_at);
