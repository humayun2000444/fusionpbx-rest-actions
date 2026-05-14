-- Branch Groups & Inter-Branch Calling
-- Run this on each FusionPBX PostgreSQL database

-- Branch Groups: Groups multiple domains as branches of one customer
CREATE TABLE IF NOT EXISTS v_branch_groups (
    branch_group_uuid UUID PRIMARY KEY,
    branch_group_name VARCHAR(255) NOT NULL,
    branch_group_description TEXT,
    branch_group_enabled VARCHAR(8) DEFAULT 'true',
    insert_date TIMESTAMP DEFAULT NOW(),
    insert_user VARCHAR(255),
    update_date TIMESTAMP,
    update_user VARCHAR(255)
);

-- Branch Members: Links domains to a branch group with a dial prefix
CREATE TABLE IF NOT EXISTS v_branch_members (
    branch_member_uuid UUID PRIMARY KEY,
    branch_group_uuid UUID NOT NULL REFERENCES v_branch_groups(branch_group_uuid) ON DELETE CASCADE,
    domain_uuid UUID NOT NULL REFERENCES v_domains(domain_uuid) ON DELETE CASCADE,
    branch_prefix VARCHAR(10) NOT NULL,
    branch_label VARCHAR(255),
    branch_member_enabled VARCHAR(8) DEFAULT 'true',
    insert_date TIMESTAMP DEFAULT NOW(),
    insert_user VARCHAR(255),
    update_date TIMESTAMP,
    update_user VARCHAR(255),
    UNIQUE(branch_group_uuid, domain_uuid),
    UNIQUE(branch_group_uuid, branch_prefix)
);

-- Index for fast lookups
CREATE INDEX IF NOT EXISTS idx_branch_members_group ON v_branch_members(branch_group_uuid);
CREATE INDEX IF NOT EXISTS idx_branch_members_domain ON v_branch_members(domain_uuid);
