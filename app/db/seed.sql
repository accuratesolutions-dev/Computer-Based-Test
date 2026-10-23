-- Starter data. Loaded ONCE, only when the `class` table is empty (see bin/init-db.php).
-- Edit freely from the admin portal afterwards.
SET NAMES utf8mb4;
INSERT IGNORE INTO `class` (`id`,`class`,`sortname`) VALUES
 (1,'Science Class','SC'),(2,'Art Class','AT'),(3,'Commercial Class','CM'),(4,'Junior Class','JS');

INSERT IGNORE INTO `subject` (`class_id`,`sub_name`) VALUES
 (1,'Physics'),(1,'Chemistry'),(1,'Biology'),(1,'Mathematics'),(1,'Further Mathematics'),
 (1,'Use of English'),(1,'Agricultural Science'),(1,'Geography'),(1,'Economics'),
 (1,'Computer Studies'),(1,'Yoruba Language'),(1,'Igbo Language'),
 (2,'Government'),(2,'I.R.S'),(2,'C.R.S'),(2,'Literature in English'),(2,'Use of English'),
 (2,'Mathematics'),(2,'Economics'),(2,'Computer Studies'),(2,'French Language'),(2,'History'),
 (2,'Fine and Applied Arts'),(2,'Yoruba Language'),(2,'Igbo Language'),
 (3,'Mathematics'),(3,'Use of English'),(3,'Account'),(3,'Commerce'),(3,'Government'),
 (3,'Economics'),(3,'Computer Studies'),(3,'Yoruba Language'),(3,'Igbo Language'),
 -- Junior Secondary (JSS 1-3)
 (4,'Mathematics'),(4,'English Language'),(4,'Physical and Health Education'),(4,'Digital Tech'),(4,'Intermediate Science'),
 (4,'Business Studies'),(4,'Cultural and Creative Arts'),(4,'Islamic Religious Studies'),(4,'Christian Religious Studies'),
 (4,'Pre-Vocational Studies (PVS)'),(4,'History'),(4,'Basic Science and Tech. (BST1)'),(4,'Computer & PHE (BST2)'),
 (4,'Social Citizenship'),(4,'French Language'),(4,'Yoruba Language'),
 -- Marketing for every senior class
 (1,'Marketing'),(2,'Marketing'),(3,'Marketing');

INSERT IGNORE INTO `app_meta` (`k`,`v`) VALUES ('junior_subjects_v2','1'),('marketing_v1','1'),('schema_v4','1'),('schema_v5','1'),('schema_v6','1');

INSERT INTO `instruction` (`text`)
SELECT * FROM (
  SELECT 'You are required to comply with the directions given by the head invigilator at the examination venue.' AS t
  UNION ALL SELECT 'Your student identity card or other valid photo identification must be visible on your desk during the entire examination.'
  UNION ALL SELECT 'If anything in the examination is unclear, contact the invigilator. Do not leave your seat without permission.'
  UNION ALL SELECT 'If you experience technical problems during the examination, you must immediately contact one of the invigilators. Failure to report such problems might be treated as an attempt to cheat.'
  UNION ALL SELECT 'The timer is controlled by the server. Your answers are submitted automatically when time runs out. Each subject can only be taken once.'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `instruction`);
