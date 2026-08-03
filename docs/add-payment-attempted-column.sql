-- Run manually against the shared CommissionDatabase (same DB backs both
-- 'ldr' and 'plaw' connections -- TblEnrollment is not duplicated per company).
--
-- NVARCHAR(20) is enough to hold either 'N/A' or a 'YYYY-MM-DD' resolution
-- date, with room to spare.

ALTER TABLE dbo.TblEnrollment
ADD Payment_Attempted NVARCHAR(20) NULL;
