# Field map — country forms built from the sheets

Generated from portal/app/sheets.php (the single source of truth for the forms, validation and export).
Each sheet column is asked once; a column that appears on several tabs is one field written to every tab on export.
"Documents status" and "Pending documents" are not typed: they are worked out from the documents checklist (files saved in the portal and documents marked received), see portal/app/documents.php.

## Italy

Export tabs (same columns and order as the sheet): Dashboard- Frontend, refunds case, Dashboard - Backend Admission, Dashboard - Backend Visa Filing

| Section | Field | Sheet column(s) | Type | Required | Edited by |
|---|---|---|---|---|---|
| Student profile | Student name | Front End B · Back End B | Text | Yes | All officers of the student |
| Student profile | Counselor | Front End C · Back End D | Text + suggestions | Yes | All officers of the student |
| Student profile | Processor assigned (front end) | Front End D | Officer | Yes | All officers of the student |
| Student profile | Processor assigned (back end) | Back End C | Officer |  | All officers of the student |
| Student profile | Received / registration date | Front End I · Back End G | Date | Yes | All officers of the student |
| Student profile | Intake | Front End G | Text + suggestions | Yes | All officers of the student |
| Student profile | Branch | Front End F · Back End F | Text + suggestions |  | All officers of the student |
| Student profile | GPA | Added in portal (not in the Italy sheets) | GPA / marks |  | All officers of the student |
| Student profile | Refund status | Front End E · Back End E · refunds case F | Text + suggestions | Yes | All officers of the student |
| Student profile | Refund reason | refunds case G | Text |  | All officers of the student |
| Student profile | Process status | Portal only | List: Active / Stopped / Handed Over / Completed |  | All officers of the student |
| Onboarding & consultancy payments | Consent form signed (Y/N) | Front End H | Yes / No |  | Front end |
| Onboarding & consultancy payments | Documents checklist provided (Y/N) | Front End K | Yes / No |  | Front end |
| Onboarding & consultancy payments | Consultancy received (1st/2nd/3rd) | Front End J | List: 1st / 2nd / 3rd |  | Front end |
| Onboarding & consultancy payments | Consultancy received (1st/2nd/3rd) - 2nd payment | Front End T | List: 1st / 2nd / 3rd |  | Front end |
| Onboarding & consultancy payments | Any discrepancies recorded (2nd payment) | Front End U | Text |  | Front end |
| Onboarding & consultancy payments | Any discrepancies recorded (3rd payment) | Front End X | Text |  | Front end |
| Onboarding & consultancy payments | Last installment received (if any) | Front End Z | Text |  | Front end |
| Meetings | Initial recorded meeting conducted by front end & counselor | Front End L | Yes / No |  | Front end |
| Meetings | How was the initial meeting held? | Portal only | Face to Face / Online / Phone |  | Front end |
| Meetings | Recorded meeting uploaded to student folder | Front End M | List: Yes / No / Yes, face to face |  | Front end |
| Meetings | Student's drive link (Zoom / recording folder) | Front End W | Link (https) |  | Front end |
| Meetings | Recording note | Front End W (text when there is no link) | Text |  | Front end |
| Meetings | Tax consultant meeting done | Front End N | Yes / No |  | Tax consultant + front end |
| Meetings | How was the tax consultant meeting held? | Portal only | Face to Face / Online / Phone |  | Tax consultant + front end |
| Meetings | Meeting priority | Front End O | Yes / No |  | Tax consultant + front end |
| Meetings | Tax consultant meeting date (held or planned) | Front End P | Date |  | Tax consultant + front end |
| Meetings | Tax consultant meeting recording link | Portal only | Link (https) |  | Tax consultant + front end |
| Meetings | Tax consultant notes | Portal only | Long text |  | Tax consultant + front end |
| Meetings | 2nd processing meeting with client | Front End Q | Yes / No |  | Front end |
| Meetings | How was the 2nd processing meeting held? | Portal only | Face to Face / Online / Phone |  | Front end |
| Meetings | If not, follow-up date | Front End R | Date |  | Front end |
| Documents | Documents | Back End H | Worked out from the documents |  | Nobody (automatic) |
| Documents | Update names of pending documents | Back End I | Worked out from the documents |  | Nobody (automatic) |
| Documents | Documents drive link | Back End J | Link (https) |  | Back end |
| Documents | Documentation request date | Back End K | Date |  | Back end |
| Documents | Documents note (portal only) | Portal only | Long text |  | Back end |
| Accounts | Email ID | Back End L | Email |  | Back end |
| Accounts | Password | Back End M | Password (encrypted) |  | Back end |
| Accounts | UniverseItaly portal ID | Back End N | Text |  | Back end |
| Accounts | Portal password | Back End O | Password (encrypted) |  | Back end |
| Admission | University shortlisting | Back End P | List: Done / Pending |  | Back end |
| Admission | Shortlisting shared with student | Back End Q | Text + suggestions |  | Back end |
| Admission | Application / offer status | Back End R · Front End S (Admission/Offer Received) | Text + suggestions |  | Back end |
| Admission | Enrollment summary status | Back End S · Front End V (Enrollment/Summary Received) | Text + suggestions |  | Back end |
| Admission | Enrollment summary received date | Back End T | Date |  | Back end |
| Installments | First installment status (Rs) | Back End U | Amount (Rs) |  | Back end |
| Installments | Second installment status (Rs) | Back End V | Amount (Rs) |  | Back end |
| Installments | Third installment status (Rs) | Back End W | Amount (Rs) |  | Back end |
| Installments | Fourth installment status (Rs) | Back End X | Amount (Rs) |  | Back end |
| Visa filing | Embassy visa submission | Visa Filing C | Text |  | Back end |
| Visa filing | Visa checklist shared | Visa Filing D | Yes / No |  | Back end |
| Visa filing | Student sponsor | Visa Filing E | Text |  | Back end |
| Visa filing | Visa checklist shared date | Visa Filing F | Date |  | Back end |
| Visa filing | Financial documents received and verified | Visa Filing G | Yes / No |  | Back end |
| Visa filing | Financial documents (missing or pending) | Visa Filing H | Text |  | Back end |
| Visa filing | CIMEA / DoV received | Visa Filing I | Yes / No |  | Back end |
| Visa filing | CIMEA / DoV received date | Visa Filing J | Date |  | Back end |
| Visa filing | Visa form filled by processor or client | Visa Filing K | List: Processor / Client |  | Back end |
| Visa filing | Health insurance purchased | Visa Filing L | Yes / No |  | Back end |
| Visa filing | Flight booking / hotel reservation | Visa Filing M | Yes / No |  | Back end |
| Visa filing | Appointment status | Visa Filing N | List: Pending / Booked / Attended / Waitlist |  | Back end |
| Visa filing | Appointment date | Visa Filing O | Date |  | Back end |
| Visa filing | Visa submitted date | Visa Filing P | Date |  | Back end |
| Visa filing | Daily client email checked (inbox / spam) | Visa Filing Q | Date |  | Back end |
| Visa filing | Visa status (Pending / Granted / Refused) | Visa Filing R · Front End Y | List: Pending / Granted / Refused |  | Back end |
| Visa filing | Visa decision received date | Visa Filing S | Date |  | Back end |
| Visa filing | Visa remarks / issues | Visa Filing T | Long text |  | Back end |
| Remarks | Front-end remarks | Front End AA | Long text |  | Front end |
| Remarks | Front-end remarks date | Front End AB | Date |  | Front end |
| Remarks | Back-end remarks | Back End Z | Long text |  | Back end |
| Remarks | Back-end remarks date | Back End AA | Date |  | Back end |
| Remarks | Student threatening cancellation / refund | Back End Y | Long text |  | Back end |

## France

Export tabs (same columns and order as the sheet): Dashboard - Backend Admission, University & Programme details

| Section | Field | Sheet column(s) | Type | Required | Edited by |
|---|---|---|---|---|---|
| Student profile | Student name | B | Text | Yes | Processor |
| Student profile | Counselor | C | Text + suggestions | Yes | Processor |
| Student profile | Processor assigned | D | Officer | Yes | Processor |
| Student profile | Registration date | G | Date | Yes | Processor |
| Student profile | Intake | K | Text + suggestions | Yes | Processor |
| Student profile | Applied for (level) | L | Text + suggestions |  | Processor |
| Student profile | Last degree | I · Programme tab C | Text |  | Processor |
| Student profile | GPA | J · Programme tab D | GPA / marks |  | Processor |
| Student profile | IELTS overall | Programme tab E | Text |  | Processor |
| Student profile | Refund status | E | Text + suggestions | Yes | Processor |
| Student profile | Process status | Portal only | List: Active / Stopped / Handed Over / Completed |  | Processor |
| Onboarding | Consent form received | H | Yes / No |  | Processor |
| Documents | CV | M | List: Done / Pending |  | Processor |
| Documents | SOP | N | List: Done / Pending |  | Processor |
| Documents | Documents status | O | Worked out from the documents |  | Nobody (automatic) |
| Documents | Update names of pending document | P | Worked out from the documents |  | Nobody (automatic) |
| Documents | Documents drive link | Q | Link (https) |  | Processor |
| Documents | Documentation request date | R | Date |  | Processor |
| Documents | Documents note (portal only) | Portal only | Long text |  | Processor |
| Accounts | Email ID | S | Email |  | Processor |
| Accounts | Password | T | Password (encrypted) |  | Processor |
| Accounts | Portal ID | V | Text |  | Processor |
| Accounts | Portal password | W | Password (encrypted) |  | Processor |
| Accounts | Recovery email | X | Email |  | Processor |
| Admission | Campus France portal | F | Text + suggestions |  | Processor |
| Admission | Mock interview | U | List: Done / Pending |  | Processor |
| Admission | University shortlisting (Done / Pending) | Y | List: Done / Pending |  | Processor |
| Admission | Application fees | Z | Text |  | Processor |
| Admission | Application status (Offer Received / Pending) | AA | Text + suggestions |  | Processor |
| Remarks | Student threatening cancellation / refund | AB | Long text |  | Processor |
| Remarks | Last contacted remarks | AC | Long text |  | Processor |
| Remarks | Last contacted date | AD | Date |  | Processor |

## Czech Republic

Export tabs (same columns and order as the sheet): Centralized Sheet, Czech Student Record, Czech Universities

| Section | Field | Sheet column(s) | Type | Required | Edited by |
|---|---|---|---|---|---|
| Student profile | Student name | Centralized B · Student Record D | Text | Yes | Processor |
| Student profile | Counselor | Centralized C | Text + suggestions | Yes | Processor |
| Student profile | Processor assigned | Centralized D | Officer | Yes | Processor |
| Student profile | Registration date | Centralized G · Student Record C | Date | Yes | Processor |
| Student profile | Intake | Centralized J · Student Record K | Text + suggestions | Yes | Processor |
| Student profile | Contact number | Student Record E | Phone |  | Processor |
| Student profile | Level | Student Record G | List: Bachelor / Master / PhD / Diploma |  | Processor |
| Student profile | Applied for | Centralized K | Text |  | Processor |
| Student profile | Last degree | Centralized H · Student Record F | Text |  | Processor |
| Student profile | GPA / CGPA | Centralized I · Student Record H | GPA / marks |  | Processor |
| Student profile | IELTS | Centralized L · Student Record I | Text |  | Processor |
| Student profile | Type | Student Record L | List: Confirm / Apply |  | Processor |
| Student profile | Branch | Centralized F | Text + suggestions |  | Processor |
| Student profile | Refund status | Centralized E | Text + suggestions |  | Processor |
| Student profile | Process status | Portal only | List: Active / Stopped / Handed Over / Completed |  | Processor |
| Documents | Documents status | Centralized M (when there is no note) | Worked out from the documents |  | Nobody (automatic) |
| Documents | Update names of pending document | Centralized N | Worked out from the documents |  | Nobody (automatic) |
| Documents | Documents drive link | Centralized O | Link (https) |  | Processor |
| Documents | Documentation request date | Centralized P | Date |  | Processor |
| Documents | Documents progress note (legalization, nostrification...) | Centralized M | Long text |  | Processor |
| Accounts | Email ID | Centralized Q · Student Record M | Email |  | Processor |
| Accounts | Password | Centralized R · Student Record N | Password (encrypted) |  | Processor |
| Admission | Admission in university | Centralized S · Student Record B | Text |  | Processor |
| Admission | Shortlisted course name | Centralized T | Text |  | Processor |
| Admission | Application fees | Centralized U | Text |  | Processor |
| Admission | Application status (Offer Received / Pending) | Centralized V | Text |  | Processor |
| Admission | 2nd consultancy | Student Record Q | Text |  | Processor |
| Remarks | Student threatening cancellation / refund | Centralized W | Long text |  | Processor |
| Remarks | Last contacted remarks | Centralized X | Long text |  | Processor |
| Remarks | Last contacted date | Centralized Y | Date |  | Processor |
| Remarks | Remarks | Student Record P | Long text |  | Processor |
| Remarks | Remarks date | Student Record O | Date |  | Processor |

## Austria

Export tabs (same columns and order as the sheet): Centralized Sheet, Austria Student Record, Austria Unviersities

| Section | Field | Sheet column(s) | Type | Required | Edited by |
|---|---|---|---|---|---|
| Student profile | Student name | Centralized B · Student Record D | Text | Yes | Processor |
| Student profile | Counselor | Centralized C | Text + suggestions | Yes | Processor |
| Student profile | Processor assigned | Centralized D | Officer | Yes | Processor |
| Student profile | Registration date | Centralized G · Student Record C | Date | Yes | Processor |
| Student profile | Intake | Centralized J · Student Record K | Text + suggestions | Yes | Processor |
| Student profile | Contact number | Student Record E | Phone |  | Processor |
| Student profile | Level | Student Record G | List: Bachelor / Master / PhD / Diploma |  | Processor |
| Student profile | Applied for | Centralized K | Text |  | Processor |
| Student profile | Last degree | Centralized H · Student Record F | Text |  | Processor |
| Student profile | GPA / CGPA | Centralized I · Student Record H | GPA / marks |  | Processor |
| Student profile | IELTS | Centralized L · Student Record I | Text |  | Processor |
| Student profile | Type | Student Record L | List: Confirm / Apply |  | Processor |
| Student profile | Branch | Centralized F | Text + suggestions |  | Processor |
| Student profile | Refund status | Centralized E | Text + suggestions |  | Processor |
| Student profile | Process status | Portal only | List: Active / Stopped / Handed Over / Completed |  | Processor |
| Documents | Documents status | Centralized M (when there is no note) | Worked out from the documents |  | Nobody (automatic) |
| Documents | Update names of pending document | Centralized N | Worked out from the documents |  | Nobody (automatic) |
| Documents | Documents drive link | Centralized O | Link (https) |  | Processor |
| Documents | Documentation request date | Centralized P | Date |  | Processor |
| Documents | Documents progress note (legalization, nostrification...) | Centralized M | Long text |  | Processor |
| Accounts | Email ID | Centralized Q · Student Record M | Email |  | Processor |
| Accounts | Password | Centralized R · Student Record N | Password (encrypted) |  | Processor |
| Admission | Admission secured (university) | Centralized S · Student Record B | Text |  | Processor |
| Admission | Shortlisted course name | Centralized T | Text |  | Processor |
| Admission | Application fees | Centralized U | Text |  | Processor |
| Admission | Application status (Offer Received / Pending) | Centralized V | Text |  | Processor |
| Remarks | Student threatening cancellation / refund | Centralized W | Long text |  | Processor |
| Remarks | Last contacted remarks | Centralized X | Long text |  | Processor |
| Remarks | Last contacted date | Centralized Y | Date |  | Processor |
| Remarks | Remarks (status) | Student Record P | Long text |  | Processor |
| Remarks | Remarks date | Student Record O | Date |  | Processor |

## Germany

Export tabs (same columns and order as the sheet): Winter GER Centralized 2026, Summer GER Client 2027, Germany Centralized Sheet, Germany Clients, Germany  Winter 2026, Germany Summer 2027

| Section | Field | Sheet column(s) | Type | Required | Edited by |
|---|---|---|---|---|---|
| Student profile | Student name | Winter/Summer B · Centralized B · Clients C | Text | Yes | Processor |
| Student profile | Counselor | Winter/Summer D · Centralized C · Clients B (Ref) | Text + suggestions | Yes | Processor |
| Student profile | Processor assigned (portal only, not in the Germany sheet) | Portal only | Officer |  | Processor |
| Student profile | Registration date | Winter M · Summer L · Centralized F · Clients F | Date | Yes | Processor |
| Student profile | Intake | Winter K · Summer J · Centralized J · Clients D | Text + suggestions | Yes | Processor |
| Student profile | Student contact number | Winter R · Summer Q · Clients G | Phone |  | Processor |
| Student profile | Level of study | Winter S · Summer R · Clients H | List: Bachelor / Master / PhD / Diploma |  | Processor |
| Student profile | Educational background / last degree | Winter H · Summer G · Centralized H · Clients I | Text |  | Processor |
| Student profile | GPA / CGPA | Winter I · Summer H · Centralized I · Clients J | GPA / marks |  | Processor |
| Student profile | Branch | Winter G · Summer F · Centralized E | Text + suggestions |  | Processor |
| Student profile | Refund status | Winter E · Summer E · Centralized D | Text + suggestions |  | Processor |
| Student profile | Reasons for refund | Winter F | Text |  | Processor |
| Student profile | Dues | Winter C · Summer C · Clients E | Text |  | Processor |
| Student profile | Process status | Portal only | List: Active / Stopped / Handed Over / Completed |  | Processor |
| Onboarding | Consent form signed | Winter L · Summer K · Centralized G · Clients K | Yes / No |  | Processor |
| Onboarding | Consultancy received (1st/2nd/3rd) | Winter N · Summer M | List: 1st / 2nd / 3rd |  | Processor |
| Onboarding | Documents checklist provided | Winter O · Summer N | Yes / No |  | Processor |
| Documents | Documents status | Centralized K | Worked out from the documents |  | Nobody (automatic) |
| Documents | Pending documents names | Centralized L | Worked out from the documents |  | Nobody (automatic) |
| Documents | Documents drive link | Winter J · Summer I · Centralized M | Link (https) |  | Processor |
| Documents | Documents note (portal only) | Portal only | Long text |  | Processor |
| Accounts | Email ID (Gmail) | Winter W · Summer U · Centralized N | Email |  | Processor |
| Accounts | Gmail password | Summer V · Centralized O | Password (encrypted) |  | Processor |
| Accounts | Recovery email | Winter X · Centralized P · Clients O | Email |  | Processor |
| Accounts | Uni-assist account | Summer W | Text |  | Processor |
| Accounts | Uni-assist password | Summer X | Password (encrypted) |  | Processor |
| Admission & visa | Admission status | Centralized Q · Winter P / Summer O (Admission/Offer Received) | List: Received / Pending / Rejected |  | Processor |
| Admission & visa | Admission letter(s) | Clients M | Text |  | Processor |
| Admission & visa | Embassy status | Winter Q · Summer P | Text + suggestions |  | Processor |
| Admission & visa | Visa status | Winter T · Summer S | List: Pending / Granted / Refused |  | Processor |
| Remarks | Remarks | Winter V · Summer Y · Clients N | Long text |  | Processor |
| Remarks | Remarks date | Winter U · Summer T · Clients L | Date |  | Processor |
| Remarks | Centralized sheet remarks | Centralized R | Long text |  | Processor |
| Remarks | Last contacted date | Centralized S | Date |  | Processor |
