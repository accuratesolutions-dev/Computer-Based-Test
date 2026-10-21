-- OPTIONAL demo data for smoke-testing (Science Class / Biology). Not loaded by default.
INSERT INTO question (class_id, subject_id, question_desc, option1, option2, option3, option4, true_answer)
SELECT s.class_id, s.sub_id, '<p>What is the basic unit of life?</p>', 'Atom', 'Cell', 'Organ', 'Molecule', 'Cell'
FROM subject s WHERE s.class_id = 1 AND s.sub_name = 'Biology';
INSERT INTO question (class_id, subject_id, question_desc, option1, option2, option3, option4, true_answer)
SELECT s.class_id, s.sub_id, '<p>Which organelle carries out photosynthesis?</p>', 'Mitochondrion', 'Nucleus', 'Chloroplast', 'Ribosome', 'Chloroplast'
FROM subject s WHERE s.class_id = 1 AND s.sub_name = 'Biology';
INSERT INTO question (class_id, subject_id, question_desc, option1, option2, option3, option4, true_answer)
SELECT s.class_id, s.sub_id, '<p>Osmosis is the movement of which substance across a membrane?</p>', 'Water', 'Glucose', 'Oxygen', 'Salt', 'Water'
FROM subject s WHERE s.class_id = 1 AND s.sub_name = 'Biology';
