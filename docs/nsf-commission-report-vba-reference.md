# NSF Commission Report — VBA Reference

Original VBA macros shared by Jacob (Jun 10, 2026). Used as the source of truth for porting
`GenerateNSFCommissionReport` to PHP/PhpSpreadsheet.

---

## Report overview

Two separate workbooks (one per company), both emailed on the **1st of each month** covering
**the previous full calendar month**.

| | LDR | Progress Law |
|---|---|---|
| Subject | NSF Commission Report - LDR | NSF Commission Report - Progress Law |
| To | candice, scarlett, rama, anthony | candice, scarlett, rama, anthony |
| CC | jacob | jacob |
| SF connector | LDR | PLAW |

Each workbook has two sheets:
- **Data** — raw rows from Snowflake (one row per contact with an NSF in the period)
- **Commission** — per-agent summary with tier-based commission calculation

---

## Snowflake query (Data sheet)

Uses `CONTACTS_USERFIELDS` for custom fields and `TRANSACTIONS` for the most-recent cleared payment.

```sql
SELECT * FROM (
    SELECT c.ID, CU1.AGENT, CU2.NSF_RETURNED_DATE, CU3.NSF_ACTION, CU4.NSF_RECOUP_DATE,
           T.CLEARED_DATE, T.N
    FROM CONTACTS c
    LEFT JOIN (SELECT CONTACT_ID, F_SHORTSTRING AS AGENT
               FROM CONTACTS_USERFIELDS WHERE CUSTOM_ID = <AGENT_ID>) CU1
           ON c.ID = CU1.CONTACT_ID
    LEFT JOIN (SELECT CONTACT_ID, F_DATE AS NSF_RETURNED_DATE
               FROM CONTACTS_USERFIELDS WHERE CUSTOM_ID = <NSF_RETURNED_ID>) CU2
           ON c.ID = CU2.CONTACT_ID
    LEFT JOIN (SELECT CONTACT_ID, F_STRING AS NSF_ACTION
               FROM CONTACTS_USERFIELDS WHERE CUSTOM_ID = <NSF_ACTION_ID>) CU3
           ON c.ID = CU3.CONTACT_ID
    LEFT JOIN (SELECT CONTACT_ID, F_DATE AS NSF_RECOUP_DATE
               FROM CONTACTS_USERFIELDS WHERE CUSTOM_ID = <NSF_RECOUP_ID>) CU4
           ON c.ID = CU4.CONTACT_ID
    LEFT JOIN (SELECT CONTACT_ID, CLEARED_DATE,
                      ROW_NUMBER() OVER(PARTITION BY CONTACT_ID ORDER BY PROCESS_DATE DESC) AS N
               FROM TRANSACTIONS
               WHERE TRANS_TYPE = 'D' AND CLEARED_DATE IS NOT NULL AND RETURNED_DATE IS NULL) T
           ON c.ID = T.CONTACT_ID
)
WHERE NSF_RETURNED_DATE >= '<start>' AND NSF_RETURNED_DATE <= '<end>' AND N = 1
```

### Custom field IDs by source

| Field | LDR CUSTOM_ID | PLAW CUSTOM_ID |
|---|---|---|
| Agent (F_SHORTSTRING) | 742134 | 742135 |
| NSF_RETURNED_DATE (F_DATE) | 742148 | 742149 |
| NSF_ACTION (F_STRING) | 742136 | 742137 |
| NSF_RECOUP_DATE (F_DATE) | 742146 | 742147 |

---

## Data sheet columns

| Col | Header | Source |
|---|---|---|
| A | ID | c.ID |
| B | AGENT | CU1.AGENT |
| C | NSF_RETURNED_DATE | CU2.NSF_RETURNED_DATE |
| D | NSF_ACTION | CU3.NSF_ACTION |
| E | NSF_RECOUP_DATE | CU4.NSF_RECOUP_DATE |
| F | CLEARED_DATE | T.CLEARED_DATE |
| G | Valid Commission | computed (see formula below) |

### Valid Commission formula

```excel
=AND(MONTH(C2)=MONTH(E2), F2<=DATE(YEAR(C2),MONTH(C2)+1,5), F2>E2)
```

In plain terms: payment is a valid commission if:
1. NSF was recouped in the same calendar month it was returned
2. The clear date is on or before the 5th of the month following the NSF return
3. The clear date is after the recoup date

---

## Commission sheet columns (A–I)

| Col | Header | Formula |
|---|---|---|
| A | NGO | Hardcoded agent names |
| B | Assignments | `=COUNTIFS(Data!B:B, A2)` |
| C | Actions | `=COUNTIFS(Data!B:B, A2, Data!D:D, "<>")` |
| D | Ratio | `=C2/B2` |
| E | Actions Tier | `=IFERROR(MATCH(D2, O$2:Q$2, 1), 0)` |
| F | Cleared Tier | `=IFERROR(MATCH(C2, N$3:N$5, 1), 0)` |
| G | Rate | `=IFERROR(INDEX(O$3:Q$5, F2, E2), 0)` |
| H | Clears | `=COUNTIFS(Data!B:B, A2, Data!G:G, TRUE)` |
| I | Commission | `=G2*H2` |

### Commission tier table (columns N–Q)

Located at N1:Q5 on the Commission sheet.

```
             │  0.2  │  0.4  │  0.6      ← ratio thresholds (Actions Tier axis)
─────────────┼───────┼───────┼──────
  1 (Clears) │ $1.50 │ $1.75 │ $2.00
 51 (Clears) │ $2.50 │ $2.75 │ $3.00
101 (Clears) │ $3.50 │ $3.75 │ $4.00
```

### Special rate overrides

The following agents always receive Rate = $4.00 regardless of tier:
- **Anthony Clark**
- **Lucas Wright**

---

## Hardcoded agent lists

### LDR Commission sheet (rows 2–10)
1. Bill Mendoza
2. Gabriel Yol
3. Harry Gardner
4. Jose Zuniga
5. Luna Bradford
6. Lucas Wright
7. Samantha Lotz
8. Timothy Phillips
9. Katherine Caceres

### PLAW Commission sheet (rows 2–7)
1. Anthony Clark
2. June Brock
3. Lucas Wright
4. Marlon Solorzano
5. Lilith Bailey
6. Oaklynn Edwards

---

## LDR VBA (original)

```vba
Sub GenerateNSFCommissionReport(Optional Dummy As Long)

Dim i                                   As Long
Dim LastRow                             As Long
Dim SQL                                 As String
Dim SendTo                              As String
Dim SendCC                              As String
Dim Subject                             As String
Dim Body                                As String
Dim Filename                            As String
Dim StartDate                           As Date
Dim EndDate                             As Date
Dim CNLDR                               As New ADODB.Connection

    Application.DisplayAlerts = False
    Application.EnableEvents = False
    Application.ScreenUpdating = False
    
    StartDate = DateSerial(Year(Date), Month(Date) - 1, 1)
    EndDate = DateSerial(Year(StartDate), Month(StartDate) + 1, 0)
       
    Workbooks.Add (1)
    Sheets.Add After:=Sheets(1)
    Sheets(1).Name = "Data"
    Sheets(2).Name = "Commission"

    SQL = "SELECT * "
    SQL = SQL & "FROM ( "
        SQL = SQL & "SELECT c.ID, CU1.AGENT, CU2.NSF_RETURNED_DATE, CU3.NSF_ACTION, CU4.NSF_RECOUP_DATE, T.CLEARED_DATE, T.N "
        SQL = SQL & "FROM CONTACTS AS c "
        SQL = SQL & "LEFT JOIN (SELECT "
            SQL = SQL & "CONTACT_ID, F_SHORTSTRING AS AGENT "
            SQL = SQL & "FROM CONTACTS_USERFIELDS "
            SQL = SQL & "WHERE CUSTOM_ID = 742134 "
            SQL = SQL & ") AS CU1 ON C.ID = CU1.CONTACT_ID "
        SQL = SQL & "LEFT JOIN (SELECT "
            SQL = SQL & "CONTACT_ID, F_DATE AS NSF_RETURNED_DATE "
            SQL = SQL & "FROM CONTACTS_USERFIELDS "
            SQL = SQL & "WHERE CUSTOM_ID = 742148 "
            SQL = SQL & ") AS CU2 ON C.ID = CU2.CONTACT_ID "
        SQL = SQL & "LEFT JOIN (SELECT "
            SQL = SQL & "CONTACT_ID, F_STRING AS NSF_ACTION "
            SQL = SQL & "FROM CONTACTS_USERFIELDS "
            SQL = SQL & "WHERE CUSTOM_ID = 742136 "
            SQL = SQL & ") AS CU3 ON C.ID = CU3.CONTACT_ID "
        SQL = SQL & "LEFT JOIN (SELECT "
            SQL = SQL & "CONTACT_ID, F_DATE AS NSF_RECOUP_DATE "
            SQL = SQL & "FROM CONTACTS_USERFIELDS "
            SQL = SQL & "WHERE CUSTOM_ID = 742146 "
            SQL = SQL & ") AS CU4 ON C.ID = CU4.CONTACT_ID "
        SQL = SQL & "LEFT JOIN (SELECT "
            SQL = SQL & "CONTACT_ID, CLEARED_DATE, ROW_NUMBER() OVER(PARTITION BY CONTACT_ID ORDER BY PROCESS_DATE DESC) AS N "
            SQL = SQL & "FROM TRANSACTIONS "
            SQL = SQL & "WHERE TRANS_TYPE = 'D' "
            SQL = SQL & "AND CLEARED_DATE IS NOT NULL "
            SQL = SQL & "AND RETURNED_DATE IS NULL "
            SQL = SQL & ") AS T ON C.ID = T.CONTACT_ID "
    SQL = SQL & ") "
    SQL = SQL & "WHERE NSF_RETURNED_DATE >= '" & StartDate & "' "
    SQL = SQL & "AND NSF_RETURNED_DATE <= '" & EndDate & "' "
    SQL = SQL & "AND N = 1 "
    
    Sheets(1).Activate
    Call GetDatabaseData(SQL, Range("A2"), CNSF, True, Nothing)
    
    Range("G1").Value = "Valid Commission"
    LastRow = Range("A" & Rows.Count).End(xlUp).Row
    Range("G2:G" & LastRow).Value = "=AND(MONTH(C2)=MONTH(E2),F2<=DATE(YEAR(C2),MONTH(C2)+1,5),F2>E2)"
    
    Call FormatReport(1)
    
    Call OpenDatabaseConnection(CNLDR)
    
    Sheets(2).Activate
    
    Range("A1").Value = "NGO"
    Range("B1").Value = "Assignments"
    Range("C1").Value = "Actions"
    Range("D1").Value = "Ratio"
    Range("E1").Value = "Actions Tier"
    Range("F1").Value = "Cleared Tier"
    Range("G1").Value = "Rate"
    Range("H1").Value = "Clears"
    Range("I1").Value = "Commission"
    Range("A2").Value = "Bill Mendoza"
    Range("A3").Value = "Gabriel Yol"
    Range("A4").Value = "Harry Gardner"
    Range("A5").Value = "Jose Zuniga"
    Range("A6").Value = "Luna Bradford"
    Range("A7").Value = "Lucas Wright"
    Range("A8").Value = "Samantha Lotz"
    Range("A9").Value = "Timothy Phillips"
    Range("A10").Value = "Katherine Caceres"
 
    Range("B2:B10").Value = "=COUNTIFS(Data!B:B,A2)"
    Range("C2:C10").Value = "=COUNTIFS(Data!B:B,A2, Data!D:D," & Chr(34) & "<>" & Chr(34) & ")"
    Range("D2:D10").Value = "=C2/B2"
    Range("E2:E10").Value = "=IFERROR(MATCH(D2,O$2:Q$2,1),0)"
    Range("F2:F10").Value = "=IFERROR(MATCH(C2,N$3:N$5,1),0)"
    Range("G2:G10").Value = "=IFERROR(INDEX(O$3:Q$5,F2,E2),0)"
    Range("H2:H10").Value = "=COUNTIFS(Data!B:B,A2,Data!G:G,TRUE)"
    Range("I2:I10").Value = "=G2*H2"
    
    ' ... [tier table, formatting, and email omitted for brevity — see full VBA below]

    Subject = "NSF Commission Report - LDR"
    SendTo = "candice@libertydebtrelief.com; scarlett@libertydebtrelief.com; rama@libertydebtrelief.com; anthony@libertydebtrelief.com"
    SendCC = "jacob@libertydebtrelief.com"

End Sub
```

---

## PLAW VBA (original)

Same structure as LDR with the following differences:

- Uses `CNPLAW` connection instead of `CNLDR`
- Different `CUSTOM_ID` values (see table above)
- Different agent list (Anthony Clark, June Brock, Lucas Wright, Marlon Solorzano, Lilith Bailey, Oaklynn Edwards — rows 2–7)
- Subject: `"NSF Commission Report - Progress Law"`
- Agents loop: `For i = 2 To 10` (VBA typo — should be `To 8`, but `A9` and `A10` are blank so no effect)
