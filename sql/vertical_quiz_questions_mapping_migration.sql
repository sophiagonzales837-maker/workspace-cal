ALTER TABLE vertical_quiz_questions
    ADD COLUMN display_question TEXT NULL,
    ADD COLUMN hidden_keyword VARCHAR(1000) NULL;
