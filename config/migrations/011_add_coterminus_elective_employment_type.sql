-- Add Co-Terminus and Elective as valid employment types
ALTER TABLE employees
  MODIFY COLUMN employment_type ENUM('Permanent','Co-Terminus','Elective','Contractual','Casual','Job Order')
  NOT NULL DEFAULT 'Permanent';
