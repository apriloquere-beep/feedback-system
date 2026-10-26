-- RETIRED: legacy department-based analytics. Do not execute against final SFMS.
-- Table used: feedbacks

-- A) Normal departments and their average rating
SELECT department, AVG(rating) AS avg_rating
FROM feedbacks
WHERE is_custom_service = 0
GROUP BY department
ORDER BY avg_rating DESC;

-- B) Custom services submitted under department = Other
SELECT service, AVG(rating) AS avg_rating
FROM feedbacks
WHERE is_custom_service = 1
GROUP BY service
ORDER BY avg_rating DESC;

-- C) Overall custom-service average rating
SELECT AVG(rating) AS overall_custom_service_avg_rating
FROM feedbacks
WHERE is_custom_service = 1;
