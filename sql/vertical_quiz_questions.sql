CREATE TABLE IF NOT EXISTS vertical_quiz_questions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vertical VARCHAR(150) NOT NULL,
    display_question TEXT NULL,
    hidden_keyword VARCHAR(1000) NULL,
    question_text TEXT NOT NULL,
    question_type VARCHAR(10) NOT NULL DEFAULT 'text',
    keywords VARCHAR(1000) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_vertical_quiz_questions_vertical (vertical)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;