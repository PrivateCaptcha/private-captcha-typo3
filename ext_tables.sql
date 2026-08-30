CREATE TABLE tx_privatecaptcha_formproof (
    nonce_hash varchar(64) DEFAULT '' NOT NULL,
    binding_hash varchar(64) DEFAULT '' NOT NULL,
    expires_at int(10) unsigned DEFAULT 0 NOT NULL,

    PRIMARY KEY (nonce_hash),
    KEY expires_at (expires_at)
);
