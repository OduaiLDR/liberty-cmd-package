SET XACT_ABORT ON;
BEGIN TRANSACTION;

DECLARE @To nvarchar(max) = N'emcmurtrey@Higbee.law';
DECLARE @CC nvarchar(max) = N'omar@libertydebtrelief.com;sam@libertydebtrelief.com;ABegg@Higbee.law;michael@libertydebtrelief.com;jacob@libertydebtrelief.com';

IF EXISTS (SELECT 1 FROM dbo.TblReports WITH (UPDLOCK, HOLDLOCK)
           WHERE Report_Name = N'LT Invoices ParamountLaw' AND Company = N'LDR')
    UPDATE dbo.TblReports
    SET Send_To = @To, Send_CC = @CC, Send_BCC = NULL
    WHERE Report_Name = N'LT Invoices ParamountLaw' AND Company = N'LDR';
ELSE
    INSERT INTO dbo.TblReports (Report_Name, Company, Send_To, Send_CC, Send_BCC, Schedule)
    VALUES (N'LT Invoices ParamountLaw', N'LDR', @To, @CC, NULL, NULL);

COMMIT TRANSACTION;

SELECT Report_Name, Company, Send_To, Send_CC, Send_BCC
FROM dbo.TblReports
WHERE Report_Name = N'LT Invoices ParamountLaw' AND Company = N'LDR';
