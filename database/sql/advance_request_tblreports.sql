/*
 * Advance Request recipients.
 * Run against the Azure SQL Server database containing dbo.TblReports.
 * The command looks up these rows by Report_Name + Company.
 */

IF EXISTS (
    SELECT 1 FROM dbo.TblReports
    WHERE Report_Name = 'AdvanceRequest' AND Company = 'LDR'
)
BEGIN
    UPDATE dbo.TblReports
    SET Send_To = 'sam@libertydebtrelief.com, omar@libertydebtrelief.com, james@nexgenfi.com',
        Send_CC = 'jacob@libertydebtrelief.com',
        Send_BCC = ''
    WHERE Report_Name = 'AdvanceRequest' AND Company = 'LDR';
END
ELSE
BEGIN
    INSERT INTO dbo.TblReports (Report_Name, Company, Send_To, Send_CC, Send_BCC)
    VALUES ('AdvanceRequest', 'LDR',
            'sam@libertydebtrelief.com, omar@libertydebtrelief.com, james@nexgenfi.com',
            'jacob@libertydebtrelief.com', '');
END;

IF EXISTS (
    SELECT 1 FROM dbo.TblReports
    WHERE Report_Name = 'AdvanceRequest' AND Company = 'PLAW'
)
BEGIN
    UPDATE dbo.TblReports
    SET Send_To = 'aaron@progresslaw.com, eric@progresslaw.com, james@nexgenfi.com',
        Send_CC = 'jacob@progresslaw.com',
        Send_BCC = ''
    WHERE Report_Name = 'AdvanceRequest' AND Company = 'PLAW';
END
ELSE
BEGIN
    INSERT INTO dbo.TblReports (Report_Name, Company, Send_To, Send_CC, Send_BCC)
    VALUES ('AdvanceRequest', 'PLAW',
            'aaron@progresslaw.com, eric@progresslaw.com, james@nexgenfi.com',
            'jacob@progresslaw.com', '');
END;

SELECT Report_Name, Company, Send_To, Send_CC, Send_BCC
FROM dbo.TblReports
WHERE Report_Name = 'AdvanceRequest'
  AND Company IN ('LDR', 'PLAW');
