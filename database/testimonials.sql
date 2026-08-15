-- ═══════════════════════════════════════════════════════════════════════
-- Testimonials / customer reviews
--
-- Public social proof needs to be a record, not markup. Storing reviews here
-- means: only approved rows are published, the aggregate rating in the
-- structured data is computed from real rows, and nobody has to edit a PHP
-- template to change what a customer said.
--
-- Deliberately NOT seeded with invented reviews. Publishing fabricated
-- testimonials — especially alongside AggregateRating markup — is both a
-- manual-action risk with search engines and a consumer-protection problem.
-- Insert real ones (see the commented example at the bottom).
-- ═══════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS testimonials (
    id            INT AUTO_INCREMENT PRIMARY KEY,

    -- Link to the real customer record where one exists. Nullable so a review
    -- from someone without an account can still be recorded.
    customer_id   INT DEFAULT NULL,
    -- The property or agent the review relates to, for context.
    property_id   INT DEFAULT NULL,
    agent_id      INT DEFAULT NULL,

    author_name   VARCHAR(100) NOT NULL,
    author_role   VARCHAR(120) DEFAULT NULL,  -- e.g. "Tenant · Borama"
    author_photo  VARCHAR(255) DEFAULT NULL,  -- uploaded path; falls back to a seeded portrait
    rating        TINYINT NOT NULL DEFAULT 5,
    body          TEXT NOT NULL,

    -- Nothing is public until a human approves it.
    is_approved   TINYINT(1) NOT NULL DEFAULT 0,
    -- Manual ordering for the home page; lower sorts first.
    sort_order    INT NOT NULL DEFAULT 0,

    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT chk_testimonial_rating CHECK (rating BETWEEN 1 AND 5),

    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE SET NULL,
    FOREIGN KEY (agent_id)    REFERENCES users(id)     ON DELETE SET NULL,

    INDEX idx_testimonials_public (is_approved, sort_order)
) ENGINE=InnoDB;

-- ───────────────────────────────────────────────────────────────────────
-- Add a real review like this, then set is_approved = 1 to publish it:
--
-- INSERT INTO testimonials
--   (author_name, author_role, rating, body, is_approved, sort_order)
-- VALUES
--   ('Full Name', 'Tenant · Borama', 5,
--    'What the customer actually said, in their own words.', 1, 10);
-- ───────────────────────────────────────────────────────────────────────
