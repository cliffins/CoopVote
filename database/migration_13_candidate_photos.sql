-- Additive storage only: existing candidate/member/election/vote rows are untouched.
CREATE TABLE IF NOT EXISTS candidate_photo_chunks (
    candidate_id INT(11) NOT NULL,
    chunk_no INT UNSIGNED NOT NULL,
    mime VARCHAR(32) NOT NULL,
    photo_data MEDIUMBLOB NOT NULL,
    PRIMARY KEY (candidate_id, chunk_no),
    CONSTRAINT fk_candidate_photo_chunks_candidate FOREIGN KEY (candidate_id)
        REFERENCES candidates(candidate_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
