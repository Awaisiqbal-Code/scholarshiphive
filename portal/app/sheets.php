<?php
declare(strict_types=1);

// =====================================================================================
// Country sheets → portal fields.
//
// This file is the single source of truth for every country form, its validation, the
// student record view and the Excel export. Each country form is
// built from that country's processing workbook(s):
//
//   Italy           Front End - Processing Team Dashboard  (tabs: Dashboard- Frontend, refunds case)
//                   BackEnd - Processing Team Dashboard    (tabs: Backend Admission, Backend Visa Filing)
//   France          France Centralized Processing sheet 2027-28 (Backend Admission, University & Programme details)
//   Czech Republic  Hamza Czech Republic and Austria (Centralized Sheet, Czech Student Record, Czech Universities)
//   Austria         same workbook (Centralized Sheet - Austria part, Austria Student Record, Austria Universities)
//   Germany         Germany, Erasmus (Winter/Summer centralized, Germany Centralized Sheet, Germany Clients,
//                   Germany Winter 2026 / Summer 2027 university lists)
//
// A column that appears on several tabs of the same workbook (name, counselor, intake, GPA,
// email, drive link ...) is ONE field: it is asked once, stored once and written to every
// tab that has it when exporting.
// =====================================================================================

const YN = ['', 'Yes', 'No'];
const LEVELS = ['', 'Bachelor', 'Master', 'PhD', 'Diploma'];
const DONE_PENDING = ['', 'Done', 'Pending'];
const REFUND_OPTIONS = ['10%', '20%', '30%', '50%', '70%', '90%', 'No Refund', 'Non-Refundable', 'Refund Initiated'];

/** Meeting formats. Keys are what is stored (students.*_mode and student_meetings.meeting_type). */
const MEETING_TYPES = [
    'Face-to-Face' => 'Face to Face (in office)',
    'Zoom Meeting' => 'Online (Zoom / Google Meet)',
    'On-Call'      => 'Phone / WhatsApp call',
];

/**
 * Field registry: key => [default label, type, options].
 * Types: text, textarea, date, yn, select (fixed list), combo (free text + suggestions), email,
 * phone, url, money, gpa, secret (stored encrypted), front_officer, back_officer, meeting_mode.
 */
const FIELDS = [
    // ---- profile ----
    'name'              => ['Student name', 'text'],
    'counselor'         => ['Counselor', 'combo'],
    'officer_id'        => ['Processor assigned (front end)', 'front_officer'],
    'back_officer_id'   => ['Processor assigned (back end)', 'back_officer'],
    'refund'            => ['Refund status', 'combo', REFUND_OPTIONS],
    'refund_reason'     => ['Refund reason', 'text'],
    'dues'              => ['Dues', 'text'],
    'branch'            => ['Branch', 'combo', ['Lahore', 'Islamabad', 'Minchinabad']],
    'received_date'     => ['Registration date', 'date'],
    'intake'            => ['Intake', 'combo'],
    'study_level'       => ['Level of study', 'select', LEVELS],
    'applied_for'       => ['Applied for', 'text'],
    'last_degree'       => ['Last degree', 'text'],
    'gpa'               => ['GPA', 'gpa'],
    'ielts'             => ['IELTS', 'text'],
    'contact_no'        => ['Contact number', 'phone'],
    'client_type'       => ['Type', 'select', ['', 'Confirm', 'Apply']],
    'process_status'    => ['Process status', 'select', ['', 'Active', 'Stopped', 'Handed Over', 'Completed']],

    // ---- onboarding / front end ----
    'consent'           => ['Consent form signed', 'yn'],
    'docs_checklist'    => ['Documents checklist provided', 'yn'],
    'consult1'          => ['Consultancy received (1st/2nd/3rd)', 'select', ['', '1st', '2nd', '3rd']],
    'consult2'          => ['Consultancy received (1st/2nd/3rd) - 2nd payment', 'select', ['', '1st', '2nd', '3rd']],
    'disc2'             => ['Any discrepancies recorded (2nd payment)', 'text'],
    'disc3'             => ['Any discrepancies recorded (3rd payment)', 'text'],
    'last_installment'  => ['Last installment received (if any)', 'text'],

    // ---- meetings ----
    'initial_meeting'      => ['Initial recorded meeting conducted by front end & counselor', 'yn'],
    'initial_meeting_mode' => ['How was the initial meeting held?', 'meeting_mode'],
    'recorded_uploaded'    => ['Recorded meeting uploaded to student folder', 'select', ['', 'Yes', 'No', 'Yes, face to face']],
    'drive_link'           => ['Student drive link (meeting recording folder)', 'url'],
    'recording_note'       => ['Recording note', 'text'],
    'tax_meeting'          => ['Tax consultant meeting done', 'yn'],
    'tax_meeting_mode'     => ['How was the tax consultant meeting held?', 'meeting_mode'],
    'meeting_priority'     => ['Meeting priority', 'yn'],
    'meeting_date'         => ['Tax consultant meeting date', 'date'],
    'tax_recording_link'   => ['Tax consultant meeting recording link', 'url'],
    'tax_remarks'          => ['Tax consultant notes', 'textarea'],
    'second_meeting'       => ['2nd processing meeting with client', 'yn'],
    'second_meeting_mode'  => ['How was the 2nd processing meeting held?', 'meeting_mode'],
    'follow_up_2'          => ['If not, follow-up date', 'date'],

    // ---- documents ----
    'documents_status'  => ['Documents status', 'select', DONE_PENDING],     // worked out from the documents (app/documents.php)
    'pending_documents' => ['Names of pending documents', 'text'],             // worked out from the documents (app/documents.php)
    'documents_note'    => ['Documents note', 'textarea'],
    'documents_link'    => ['Documents drive link', 'url'],
    'doc_request_date'  => ['Documentation request date', 'date'],
    'cv_status'         => ['CV', 'select', DONE_PENDING],
    'sop_status'        => ['SOP', 'select', DONE_PENDING],

    // ---- admission ----
    'campus_france'     => ['Campus France portal', 'combo', ['Done', 'yes (complete)', 'account create but not upload documents']],
    'mock_interview'    => ['Mock interview', 'select', DONE_PENDING],
    'shortlisting'      => ['University shortlisting', 'select', DONE_PENDING],
    'shortlisting_shared' => ['Shortlisting shared with student', 'combo', ['Shared with student', 'Not shared with student']],
    'shortlisted_course' => ['Shortlisted course name', 'text'],
    'admission_university' => ['Admission in university', 'text'],
    'application_fees'  => ['Application fees', 'text'],
    'application_status' => ['Application status (Offer Received / Pending)', 'combo', ['Offer Received', 'Pending']],
    'admission_letters' => ['Admission letter', 'text'],
    'enrollment_summary_status' => ['Enrollment summary status', 'combo', ['Received', 'Pending']],
    'enrollment_summary_date'   => ['Enrollment summary received date', 'date'],
    'second_consultancy' => ['2nd consultancy', 'text'],
    'embassy_status'    => ['Embassy status', 'combo', ['Waiting for Admission', 'Waiting List Join', 'Waiting for Blocked Amount', 'Appointment Received', 'Refund']],

    // ---- accounts (passwords are stored encrypted) ----
    'student_email'       => ['Email ID', 'email'],
    'email_password_enc'  => ['Email password', 'secret'],
    'recovery_email'      => ['Recovery email', 'email'],
    'portal_id'           => ['Portal ID', 'text'],
    'portal_password_enc' => ['Portal password', 'secret'],

    // ---- installments ----
    'inst1'             => ['First installment (Rs)', 'money'],
    'inst2'             => ['Second installment (Rs)', 'money'],
    'inst3'             => ['Third installment (Rs)', 'money'],
    'inst4'             => ['Fourth installment (Rs)', 'money'],

    // ---- visa filing ----
    'embassy'               => ['Embassy visa submission', 'text'],
    'visa_checklist_shared' => ['Visa checklist shared', 'yn'],
    'sponsor'               => ['Student sponsor', 'text'],
    'visa_checklist_date'   => ['Visa checklist shared date', 'date'],
    'financial_verified'    => ['Financial documents received and verified', 'yn'],
    'financial_missing'     => ['Financial documents (missing or pending)', 'text'],
    'cimea_received'        => ['CIMEA / DoV received', 'yn'],
    'cimea_date'            => ['CIMEA / DoV received date', 'date'],
    'visa_form_by'          => ['Visa form filled by', 'select', ['', 'Processor', 'Client']],
    'health_insurance'      => ['Health insurance purchased', 'yn'],
    'flight_hotel'          => ['Flight booking / hotel reservation', 'yn'],
    'appointment_status'    => ['Appointment status', 'select', ['', 'Pending', 'Booked', 'Attended', 'Waitlist']],
    'appointment_date'      => ['Appointment date', 'date'],
    'visa_submitted_date'   => ['Visa submitted date', 'date'],
    'email_checked_date'    => ['Client inbox / spam last checked', 'date'],
    'visa'                  => ['Visa status', 'select', ['', 'Pending', 'Granted', 'Refused']],
    'visa_decision_date'    => ['Visa decision received date', 'date'],
    'visa_remarks'          => ['Visa remarks / issues', 'textarea'],

    // ---- remarks ----
    'remarks'             => ['Remarks', 'textarea'],
    'remarks_date'        => ['Remarks date', 'date'],
    'back_remarks'        => ['Last contacted remarks', 'textarea'],
    'back_remarks_date'   => ['Last contacted date', 'date'],
    'record_remarks'      => ['Student record remarks', 'textarea'],
    'record_remarks_date' => ['Student record remarks date', 'date'],
    'refund_threat'       => ['Student threatening cancellation / refund', 'textarea'],

    // ---- older columns kept for existing data (shown only when a record already has a value) ----
    'follow_up_1'       => ['Initial meeting follow-up date', 'date'],
    'follow_up_3'       => ['Tax meeting follow-up date', 'date'],
    'consult3'          => ['3rd consultancy received', 'text'],
    'offer'             => ['Admission / offer received', 'yn'],
    'enrollment'        => ['Enrollment / summary received', 'yn'],
    'legalization_status' => ['Legalization status', 'text'],
    'application_notes' => ['Application notes', 'textarea'],
    'total_package_fee' => ['Total package fee (Rs)', 'money'],
    'payment_status'    => ['Payment status', 'text'],
    'nostrification_status' => ['Nostrification status', 'text'],
    'aps_number'        => ['APS certificate number', 'text'],
];

/** Fields that are derived or kept only for older data: never asked on a new form. */
const LEGACY_FIELDS = ['follow_up_1', 'follow_up_3', 'consult3', 'offer', 'enrollment', 'legalization_status',
    'application_notes', 'total_package_fee', 'payment_status', 'nostrification_status', 'aps_number'];

// -------------------------------------------------------------------------------------
// Form layout per country.
// Each section: [title, team, [field => overrides]]. Overrides: label, req (required),
// type, options, team (per-field team), col (sheet column reference shown under the label).
// Teams decide who edits: basic = every officer of the student, frontend, backend, tax.
// -------------------------------------------------------------------------------------

function country_form_spec(string $c): array {
    $R = ['req' => true];
    return match ($c) {
        'italy' => [
            ['Student profile', 'basic', [
                'name'            => $R + ['col' => 'Front End B · Back End B'],
                'counselor'       => $R + ['col' => 'Front End C · Back End D'],
                'officer_id'      => ['label' => 'Processor assigned (front end)', 'req' => true, 'col' => 'Front End D'],
                'back_officer_id' => ['label' => 'Processor assigned (back end)', 'col' => 'Back End C'],
                'received_date'   => $R + ['label' => 'Received / registration date', 'col' => 'Front End I · Back End G'],
                'intake'          => $R + ['col' => 'Front End G'],
                'branch'          => ['col' => 'Front End F · Back End F'],
                'gpa'             => ['col' => 'Added in portal (not in the Italy sheets)'],
                'refund'          => $R + ['col' => 'Front End E · Back End E · refunds case F'],
                'refund_reason'   => ['col' => 'refunds case G'],
                'process_status'  => [],
            ]],
            ['Onboarding & consultancy payments', 'frontend', [
                'consent'          => ['label' => 'Consent form signed (Y/N)', 'col' => 'Front End H'],
                'docs_checklist'   => ['label' => 'Documents checklist provided (Y/N)', 'col' => 'Front End K'],
                'consult1'         => ['col' => 'Front End J'],
                'consult2'         => ['col' => 'Front End T'],
                'disc2'            => ['col' => 'Front End U'],
                'disc3'            => ['col' => 'Front End X'],
                'last_installment' => ['col' => 'Front End Z'],
            ]],
            ['Meetings', 'frontend', [
                'initial_meeting'      => ['col' => 'Front End L'],
                'initial_meeting_mode' => [],
                'recorded_uploaded'    => ['col' => 'Front End M'],
                'drive_link'           => ['label' => "Student's drive link (Zoom / recording folder)", 'col' => 'Front End W'],
                'recording_note'       => ['col' => 'Front End W (text when there is no link)'],
                'tax_meeting'          => ['team' => 'tax', 'col' => 'Front End N'],
                'tax_meeting_mode'     => ['team' => 'tax'],
                'meeting_priority'     => ['team' => 'tax', 'col' => 'Front End O'],
                'meeting_date'         => ['team' => 'tax', 'label' => 'Tax consultant meeting date (held or planned)', 'col' => 'Front End P'],
                'tax_recording_link'   => ['team' => 'tax'],
                'tax_remarks'          => ['team' => 'tax'],
                'second_meeting'       => ['col' => 'Front End Q'],
                'second_meeting_mode'  => [],
                'follow_up_2'          => ['col' => 'Front End R'],
            ]],
            ['Documents', 'backend', [
                'documents_status'  => ['label' => 'Documents', 'col' => 'Back End H'],
                'pending_documents' => ['label' => 'Update names of pending documents', 'col' => 'Back End I'],
                'documents_link'    => ['col' => 'Back End J'],
                'doc_request_date'  => ['col' => 'Back End K'],
                'documents_note'    => ['label' => 'Documents note (portal only)'],
            ]],
            ['Accounts', 'backend', [
                'student_email'       => ['col' => 'Back End L'],
                'email_password_enc'  => ['label' => 'Password', 'col' => 'Back End M'],
                'portal_id'           => ['label' => 'UniverseItaly portal ID', 'col' => 'Back End N'],
                'portal_password_enc' => ['label' => 'Portal password', 'col' => 'Back End O'],
            ]],
            ['Admission', 'backend', [
                'shortlisting'              => ['col' => 'Back End P'],
                'shortlisting_shared'       => ['col' => 'Back End Q'],
                'application_status'        => ['label' => 'Application / offer status', 'col' => 'Back End R · Front End S (Admission/Offer Received)'],
                'enrollment_summary_status' => ['col' => 'Back End S · Front End V (Enrollment/Summary Received)'],
                'enrollment_summary_date'   => ['col' => 'Back End T'],
            ]],
            ['Installments', 'backend', [
                'inst1' => ['label' => 'First installment status (Rs)', 'col' => 'Back End U'],
                'inst2' => ['label' => 'Second installment status (Rs)', 'col' => 'Back End V'],
                'inst3' => ['label' => 'Third installment status (Rs)', 'col' => 'Back End W'],
                'inst4' => ['label' => 'Fourth installment status (Rs)', 'col' => 'Back End X'],
            ]],
            ['Visa filing', 'backend', [
                'embassy'               => ['col' => 'Visa Filing C'],
                'visa_checklist_shared' => ['col' => 'Visa Filing D'],
                'sponsor'               => ['col' => 'Visa Filing E'],
                'visa_checklist_date'   => ['col' => 'Visa Filing F'],
                'financial_verified'    => ['col' => 'Visa Filing G'],
                'financial_missing'     => ['col' => 'Visa Filing H'],
                'cimea_received'        => ['col' => 'Visa Filing I'],
                'cimea_date'            => ['col' => 'Visa Filing J'],
                'visa_form_by'          => ['label' => 'Visa form filled by processor or client', 'col' => 'Visa Filing K'],
                'health_insurance'      => ['col' => 'Visa Filing L'],
                'flight_hotel'          => ['col' => 'Visa Filing M'],
                'appointment_status'    => ['col' => 'Visa Filing N'],
                'appointment_date'      => ['col' => 'Visa Filing O'],
                'visa_submitted_date'   => ['col' => 'Visa Filing P'],
                'email_checked_date'    => ['label' => 'Daily client email checked (inbox / spam)', 'col' => 'Visa Filing Q'],
                'visa'                  => ['label' => 'Visa status (Pending / Granted / Refused)', 'col' => 'Visa Filing R · Front End Y'],
                'visa_decision_date'    => ['col' => 'Visa Filing S'],
                'visa_remarks'          => ['col' => 'Visa Filing T'],
            ]],
            ['Remarks', 'frontend', [
                'remarks'           => ['label' => 'Front-end remarks', 'col' => 'Front End AA'],
                'remarks_date'      => ['label' => 'Front-end remarks date', 'col' => 'Front End AB'],
                'back_remarks'      => ['team' => 'backend', 'label' => 'Back-end remarks', 'col' => 'Back End Z'],
                'back_remarks_date' => ['team' => 'backend', 'label' => 'Back-end remarks date', 'col' => 'Back End AA'],
                'refund_threat'     => ['team' => 'backend', 'col' => 'Back End Y'],
            ]],
        ],

        'france' => [
            ['Student profile', 'basic', [
                'name'            => $R + ['col' => 'B'],
                'counselor'       => $R + ['col' => 'C'],
                'back_officer_id' => ['label' => 'Processor assigned', 'req' => true, 'col' => 'D'],
                'received_date'   => $R + ['col' => 'G'],
                'intake'          => $R + ['col' => 'K'],
                'applied_for'     => ['type' => 'combo', 'options' => ['Masters', 'Bachelors'], 'label' => 'Applied for (level)', 'col' => 'L'],
                'last_degree'     => ['col' => 'I · Programme tab C'],
                'gpa'             => ['col' => 'J · Programme tab D'],
                'ielts'           => ['label' => 'IELTS overall', 'col' => 'Programme tab E'],
                'refund'          => $R + ['col' => 'E'],
                'process_status'  => [],
            ]],
            ['Onboarding', 'frontend', [
                'consent' => ['label' => 'Consent form received', 'col' => 'H'],
            ]],
            ['Documents', 'backend', [
                'cv_status'         => ['col' => 'M'],
                'sop_status'        => ['col' => 'N'],
                'documents_status'  => ['col' => 'O'],
                'pending_documents' => ['label' => 'Update names of pending document', 'col' => 'P'],
                'documents_link'    => ['col' => 'Q'],
                'doc_request_date'  => ['col' => 'R'],
                'documents_note'    => ['label' => 'Documents note (portal only)'],
            ]],
            ['Accounts', 'backend', [
                'student_email'       => ['col' => 'S'],
                'email_password_enc'  => ['label' => 'Password', 'col' => 'T'],
                'portal_id'           => ['label' => 'Portal ID', 'col' => 'V'],
                'portal_password_enc' => ['label' => 'Portal password', 'col' => 'W'],
                'recovery_email'      => ['col' => 'X'],
            ]],
            ['Admission', 'backend', [
                'campus_france'      => ['col' => 'F'],
                'mock_interview'     => ['col' => 'U'],
                'shortlisting'       => ['label' => 'University shortlisting (Done / Pending)', 'col' => 'Y'],
                'application_fees'   => ['col' => 'Z'],
                'application_status' => ['col' => 'AA'],
            ]],
            ['Remarks', 'backend', [
                'refund_threat'     => ['col' => 'AB'],
                'back_remarks'      => ['label' => 'Last contacted remarks', 'col' => 'AC'],
                'back_remarks_date' => ['label' => 'Last contacted date', 'col' => 'AD'],
            ]],
        ],

        'czech', 'austria' => [
            ['Student profile', 'basic', [
                'name'            => $R + ['col' => 'Centralized B · Student Record D'],
                'counselor'       => $R + ['col' => 'Centralized C'],
                'back_officer_id' => ['label' => 'Processor assigned', 'req' => true, 'col' => 'Centralized D'],
                'received_date'   => $R + ['col' => 'Centralized G · Student Record C'],
                'intake'          => $R + ['col' => 'Centralized J · Student Record K'],
                'contact_no'      => ['col' => 'Student Record E'],
                'study_level'     => ['label' => 'Level', 'col' => 'Student Record G'],
                'applied_for'     => ['col' => 'Centralized K'],
                'last_degree'     => ['col' => 'Centralized H · Student Record F'],
                'gpa'             => ['label' => 'GPA / CGPA', 'col' => 'Centralized I · Student Record H'],
                'ielts'           => ['col' => 'Centralized L · Student Record I'],
                'client_type'     => ['col' => 'Student Record L'],
                'branch'          => ['col' => 'Centralized F'],
                'refund'          => ['col' => 'Centralized E'],
                'process_status'  => [],
            ]],
            ['Documents', 'backend', [
                'documents_status'  => ['col' => 'Centralized M (when there is no note)'],
                'pending_documents' => ['label' => 'Update names of pending document', 'col' => 'Centralized N'],
                'documents_link'    => ['col' => 'Centralized O'],
                'doc_request_date'  => ['col' => 'Centralized P'],
                'documents_note'    => ['label' => 'Documents progress note (legalization, nostrification...)', 'col' => 'Centralized M'],
            ]],
            ['Accounts', 'backend', [
                'student_email'      => ['col' => 'Centralized Q · Student Record M'],
                'email_password_enc' => ['label' => 'Password', 'col' => 'Centralized R · Student Record N'],
            ]],
            ['Admission', 'backend', [
                'admission_university' => ['label' => $c === 'austria' ? 'Admission secured (university)' : 'Admission in university', 'col' => 'Centralized S · Student Record B'],
                'shortlisted_course'   => ['col' => 'Centralized T'],
                'application_fees'     => ['col' => 'Centralized U'],
                'application_status'   => ['type' => 'text', 'col' => 'Centralized V'],
            ] + ($c === 'czech' ? ['second_consultancy' => ['col' => 'Student Record Q']] : [])],
            ['Remarks', 'backend', [
                'refund_threat'       => ['col' => 'Centralized W'],
                'back_remarks'        => ['label' => 'Last contacted remarks', 'col' => 'Centralized X'],
                'back_remarks_date'   => ['label' => 'Last contacted date', 'col' => 'Centralized Y'],
                'record_remarks'      => ['label' => $c === 'austria' ? 'Remarks (status)' : 'Remarks', 'col' => 'Student Record P'],
                'record_remarks_date' => ['label' => 'Remarks date', 'col' => 'Student Record O'],
            ]],
        ],

        'germany' => [
            ['Student profile', 'basic', [
                'name'            => $R + ['col' => 'Winter/Summer B · Centralized B · Clients C'],
                'counselor'       => $R + ['col' => 'Winter/Summer D · Centralized C · Clients B (Ref)'],
                'back_officer_id' => ['label' => 'Processor assigned (portal only, not in the Germany sheet)'],
                'received_date'   => $R + ['col' => 'Winter M · Summer L · Centralized F · Clients F'],
                'intake'          => $R + ['col' => 'Winter K · Summer J · Centralized J · Clients D'],
                'contact_no'      => ['label' => 'Student contact number', 'col' => 'Winter R · Summer Q · Clients G'],
                'study_level'     => ['col' => 'Winter S · Summer R · Clients H'],
                'last_degree'     => ['label' => 'Educational background / last degree', 'col' => 'Winter H · Summer G · Centralized H · Clients I'],
                'gpa'             => ['label' => 'GPA / CGPA', 'col' => 'Winter I · Summer H · Centralized I · Clients J'],
                'branch'          => ['col' => 'Winter G · Summer F · Centralized E'],
                'refund'          => ['col' => 'Winter E · Summer E · Centralized D'],
                'refund_reason'   => ['label' => 'Reasons for refund', 'col' => 'Winter F'],
                'dues'            => ['col' => 'Winter C · Summer C · Clients E'],
                'process_status'  => [],
            ]],
            ['Onboarding', 'frontend', [
                'consent'        => ['col' => 'Winter L · Summer K · Centralized G · Clients K'],
                'consult1'       => ['col' => 'Winter N · Summer M'],
                'docs_checklist' => ['col' => 'Winter O · Summer N'],
            ]],
            ['Documents', 'backend', [
                'documents_status'  => ['label' => 'Documents status', 'options' => ['', 'Completed', 'Pending'], 'col' => 'Centralized K'],
                'pending_documents' => ['label' => 'Pending documents names', 'col' => 'Centralized L'],
                'documents_link'    => ['label' => 'Documents drive link', 'col' => 'Winter J · Summer I · Centralized M'],
                'documents_note'    => ['label' => 'Documents note (portal only)'],
            ]],
            ['Accounts', 'backend', [
                'student_email'       => ['label' => 'Email ID (Gmail)', 'col' => 'Winter W · Summer U · Centralized N'],
                'email_password_enc'  => ['label' => 'Gmail password', 'col' => 'Summer V · Centralized O'],
                'recovery_email'      => ['col' => 'Winter X · Centralized P · Clients O'],
                'portal_id'           => ['label' => 'Uni-assist account', 'col' => 'Summer W'],
                'portal_password_enc' => ['label' => 'Uni-assist password', 'col' => 'Summer X'],
            ]],
            ['Admission & visa', 'backend', [
                'application_status' => ['label' => 'Admission status', 'type' => 'select', 'options' => ['', 'Received', 'Pending', 'Rejected'], 'col' => 'Centralized Q · Winter P / Summer O (Admission/Offer Received)'],
                'admission_letters'  => ['label' => 'Admission letter(s)', 'col' => 'Clients M'],
                'embassy_status'     => ['col' => 'Winter Q · Summer P'],
                'visa'               => ['col' => 'Winter T · Summer S'],
            ]],
            ['Remarks', 'basic', [
                'remarks'           => ['label' => 'Remarks', 'col' => 'Winter V · Summer Y · Clients N'],
                'remarks_date'      => ['label' => 'Remarks date', 'col' => 'Winter U · Summer T · Clients L'],
                'back_remarks'      => ['team' => 'backend', 'label' => 'Centralized sheet remarks', 'col' => 'Centralized R'],
                'back_remarks_date' => ['team' => 'backend', 'label' => 'Last contacted date', 'col' => 'Centralized S'],
            ]],
        ],
        default => [],
    };
}

/**
 * Resolved form for one country: [title => ['team' => t, 'fields' => [key => def]]].
 * def = ['label', 'type', 'options', 'req', 'team', 'col'].
 */
function country_groups(string $c): array {
    static $cache = [];
    if (isset($cache[$c])) return $cache[$c];
    $out = [];
    foreach (country_form_spec($c) as [$title, $team, $fields]) {
        $res = [];
        foreach ($fields as $k => $o) {
            $base = FIELDS[$k] ?? [ucfirst(str_replace('_', ' ', $k)), 'text'];
            $res[$k] = [
                'label'   => $o['label'] ?? $base[0],
                'type'    => $o['type'] ?? $base[1],
                'options' => $o['options'] ?? ($base[2] ?? []),
                'req'     => !empty($o['req']),
                'team'    => $o['team'] ?? $team,
                'col'     => $o['col'] ?? '',
            ];
        }
        $out[$title] = ['team' => $team, 'fields' => $res];
    }
    return $cache[$c] = $out;
}

/** Every field of a country form, flat: key => def. */
function country_fields(string $c): array {
    $out = [];
    foreach (country_groups($c) as $g) $out += $g['fields'];
    return $out;
}

/** Legacy / non-sheet fields that hold a value on this record (so nothing already typed is hidden). */
function extra_fields_with_data(array $r): array {
    $form = country_fields($r['country'] ?? 'italy');
    $out = [];
    foreach (FIELDS as $k => $base) {
        if (isset($form[$k]) || in_array($base[1], ['secret', 'front_officer', 'back_officer'], true)) continue;
        if (!array_key_exists($k, $r) || $r[$k] === null || trim((string)$r[$k]) === '') continue;
        $out[$k] = ['label' => $base[0], 'type' => $base[1], 'options' => $base[2] ?? [], 'req' => false,
                    'team' => 'backend', 'col' => 'Not in the ' . country_name($r['country'] ?? '') . ' sheet (older data)'];
    }
    return $out;
}

// -------------------------------------------------------------------------------------
// Export layout: the workbook tabs and columns, in the same order as the sheets.
// Column source: a field key, or '@name' for a computed value (see export_cell()).
// Tab rows: 'students' (one row per student) or 'apps' (one row per university application).
// -------------------------------------------------------------------------------------

function country_sheet_tabs(string $c): array {
    return match ($c) {
        'italy' => [
            'Dashboard- Frontend' => ['rows' => 'students', 'cols' => [
                ['SR', '@sr'], ['Student Name', 'name'], ['Counselor', 'counselor'], ['Processor Assigned', '@front_officer'],
                ['Refund Status', 'refund'], ['Branch', 'branch'], ['Intake', 'intake'], ['Consent Form Signed (Y/N)', 'consent'],
                ['Received Date', 'received_date'], ['Consultancy Received (1st/2nd/3rd)', 'consult1'],
                ['Documents checklist provided (Y/N)', 'docs_checklist'],
                ['Initial Recorded Meeting Conducted by Front end & Counselor (Y/N)', '@initial_meeting_sheet'],
                ['Recorded Meeting Uploaded Student Folder (Y/N)', 'recorded_uploaded'],
                ['Tax Consultant Meeting Done', 'tax_meeting'], ['Meeting Priority', 'meeting_priority'], ['Date', 'meeting_date'],
                ['2ND Processing Online meeting with Client', '@second_meeting_sheet'], ['If Not than Follow up Date', 'follow_up_2'],
                ['Admission/Offer Received', '@offer_yn'], ['Consultancy Received (1st/2nd/3rd)', 'consult2'],
                ['Any Descripencies Recorded (2nd Payment)', 'disc2'], ['Enrollement/Summary Received', '@enrollment_yn'],
                ['Students Dive link', '@drive_link_or_note'], ['Any Descripencies Recorded (3rd Payment)', 'disc3'],
                ['Visa Granted/Refused', '@visa_granted_refused'], ['Last Installement Received (If Any)', 'last_installment'],
                ['Remarks', 'remarks'], ['Remarks', 'remarks_date'],
                ['GPA (added in portal)', 'gpa'],
            ]],
            'refunds case' => ['rows' => 'students', 'cols' => [
                ['SR', '@row'], ['SR', '@sr'], ['Student Name', 'name'], ['Counselor', 'counselor'], ['Processor Assigned', '@front_officer'],
                ['Refund Status', 'refund'], ['Refund Reason', 'refund_reason'],
            ]],
            'Dashboard - Backend Admission' => ['rows' => 'students', 'cols' => [
                ['SR', '@sr'], ['Student Name', 'name'], ['Processor Assigned', '@back_officer'], ['Counselor', 'counselor'],
                ['Refund Status', 'refund'], ['Branch', 'branch'], ['Registeration Date', 'received_date'],
                ['documents', 'documents_status'], ['Update Names of Pending Document', 'pending_documents'],
                ['Documents Drive Link', 'documents_link'], ['Documentation Request Date', 'doc_request_date'],
                ['Email Id', 'student_email'], ['Password', 'email_password_enc'], ['UniverseItaly Portal ID', 'portal_id'],
                ['Portal Password', 'portal_password_enc'], ['University Shortlisting', '@shortlisting_italy'],
                ['Shortlisting Shared With Student', 'shortlisting_shared'], ['Application Status (Offer Received/Pending)', '@application_status'],
                ['Enrollement Summary Status', 'enrollment_summary_status'], ['Enrollement Summary Received Date', 'enrollment_summary_date'],
                ['First Installment Status', 'inst1'], ['Second Installment Status', 'inst2'], ['Third Installment Status', 'inst3'],
                ['Fourth Installment Status', 'inst4'], ['Student threatening cancellation/refund', 'refund_threat'],
                ['Remarks', 'back_remarks'], ['Date', 'back_remarks_date'],
            ]],
            'Dashboard - Backend Visa Filing' => ['rows' => 'students', 'cols' => [
                ['Student Name', 'name'], ['Processor Assigned', '@back_officer'], ['Embassy Visa Submission', 'embassy'],
                ['Visa Checklist Shared', 'visa_checklist_shared'], ['Student Sponsor', 'sponsor'], ['Visa Checklist Shared Date', 'visa_checklist_date'],
                ['Financial Documents Received and Verified', 'financial_verified'], ['Financial Documents (Missing or pending)', 'financial_missing'],
                ['CIMEA/DoV Received', 'cimea_received'], ['CIMEA/DoV Received Date', 'cimea_date'],
                ['Visa Form Filled by Processor or Client', 'visa_form_by'], ['Health Insurance Purchased', 'health_insurance'],
                ['Flight Booking/Hotel Reservations', 'flight_hotel'], ['Appointment Status', 'appointment_status'],
                ['Appointment Date', 'appointment_date'], ['Visa Submitted Date', 'visa_submitted_date'],
                ['Daily Client Email Checked Inbox/Spam Emails', 'email_checked_date'], ['Visa Status (Pending/Received)', '@visa_pending_received'],
                ['Visa Decision Received Date', 'visa_decision_date'], ['Remarks/Issues if Any', 'visa_remarks'],
            ]],
        ],
        'france' => [
            'Dashboard - Backend Admission' => ['rows' => 'students', 'cols' => [
                ['Sr No.', '@sr'], ['Student Name', 'name'], ['Counselor', 'counselor'], ['Processor Assigned', '@back_officer'],
                ['Refund Status', 'refund'], ['Campus France portal', 'campus_france'], ['Registeration Date', 'received_date'],
                ['Consent Form Received', 'consent'], ['Last Degree', 'last_degree'], ['GPA', 'gpa'], ['Intake', 'intake'],
                ['Applied For', 'applied_for'], ['CV', 'cv_status'], ['SOP', 'sop_status'], ['DOCUMENTS Status', 'documents_status'],
                ['Update Names of Pending Document', 'pending_documents'], ['Documents Drive Link', 'documents_link'],
                ['Documentation Request Date', 'doc_request_date'], ['Email Id', 'student_email'], ['Password', 'email_password_enc'],
                ['Mock interview', 'mock_interview'], ['Portal ID', 'portal_id'], ['Password', 'portal_password_enc'],
                ['Recovery Email', 'recovery_email'], ['University Shortlisting (Done/Pending)', 'shortlisting'],
                ['Application Fees', 'application_fees'], ['Application Status (Offer Received/Pending)', '@application_status'],
                ['Student threatening cancellation/refund', 'refund_threat'], ['Last Contacted Remarks by Usman', 'back_remarks'],
                ['Last Contacted Date', 'back_remarks_date'],
            ]],
            'University & Programme details' => ['rows' => 'apps', 'cols' => [
                ['#', '@student_no'], ['Student Name', '@first:name'], ['Previous Degree', '@first:last_degree'], ['CGPA / Marks', '@first:gpa'],
                ['IELTS Overall', '@first:ielts'], ['Prog #', '@app_no'], ['University', 'a:university'], ['Program / Track', 'a:program'],
                ['Application Status', 'a:status'], ['REMARKS', 'a:notes'], ['Language / Note', 'a:language'], ['Official Link', 'a:link'],
            ]],
        ],
        'czech', 'austria' => [
            'Centralized Sheet' => ['rows' => 'students', 'cols' => [
                ['Sr', '@sr'], ['Student Name', 'name'], ['Counselor', 'counselor'], ['Processor Assigned', '@back_officer'],
                ['Refund Status', 'refund'], ['Branch', 'branch'], ['Registeration Date', 'received_date'], ['Last Degree', 'last_degree'],
                ['GPA', 'gpa'], ['Intake', 'intake'], ['Applied For', 'applied_for'], ['IELTS', 'ielts'],
                ['DOCUMENTS Status', '@docs_status_or_note'], ['Update Names of Pending Document', 'pending_documents'],
                ['Documents Drive Link', 'documents_link'], ['Documentation Request Date', 'doc_request_date'], ['Email Id', 'student_email'],
                ['Password', 'email_password_enc'], ['Admission in University', 'admission_university'],
                ['Shortlisted Course Name', 'shortlisted_course'], ['Application Fees', 'application_fees'],
                ['Application Status (Offer Received/Pending)', 'application_status'], ['Student threatening cancellation/refund', 'refund_threat'],
                ['Last Contacted Remarks', 'back_remarks'], ['Last Contacted Date', 'back_remarks_date'],
            ]],
            ($c === 'austria' ? 'Austria Student Record' : 'Czech Student Record') => ['rows' => 'students', 'cols' => array_merge([
                [$c === 'austria' ? 'Sr' : '', '@row'], [$c === 'austria' ? 'Admission Secured' : 'Admission', 'admission_university'],
                ['Date', 'received_date'], ['Student Name', 'name'], ['Contact No', 'contact_no'], ['Last Degree', 'last_degree'],
                ['Level', 'study_level'], ['CGPA', 'gpa'], ['IELTS', 'ielts'], ['Interested Country', '@country_name'], ['Intake', 'intake'],
                ['Type', 'client_type'], ['Email', 'student_email'], ['Passowrd', 'email_password_enc'], ['Date', 'record_remarks_date'],
                [$c === 'austria' ? 'Remarks(Status)' : 'Remarks', 'record_remarks'],
            ], $c === 'czech' ? [['2nd Consultancy', 'second_consultancy']] : [])],
            ($c === 'austria' ? 'Austria Unviersities' : 'Czech Universities') => ['rows' => 'apps', 'cols' => array_merge(
                $c === 'austria' ? [['Sr.', '@student_no'], ['Admission', '@first:admission_university']] : [['', '@student_no']], [
                ['Student Name', '@first:name'], ['Country', '@country_name'], ['University', 'a:university'], ['Program', 'a:program'],
                ['Application Fee', 'a:fee'], ['Nostrification Status', 'a:nostrification_status'], ['Nostification Fee', 'a:nostrification_fee'],
                ['Starting', 'a:starting'], ['Deadline', 'a:deadline'], ['Applied Date', 'a:applied_date'], ['Offer Status', 'a:status'],
                [$c === 'austria' ? 'Portal Links' : 'Portal Link', 'a:link'],
            ])],
        ],
        'germany' => [
            'Winter GER Centralized 2026' => ['rows' => 'students', 'filter' => 'winter', 'cols' => [   // column W has no title in the sheet; it holds the Gmail
                ['Sr. No', '@sr'], ['Student Name', 'name'], ['Dues', 'dues'], ['Counselor', 'counselor'], ['Refund Status', 'refund'],
                ['Reasons for Refund', 'refund_reason'], ['Branch', 'branch'], ['Educational Background', 'last_degree'], ['CGPA', 'gpa'],
                ['Drive Link', 'documents_link'], ['Intake', 'intake'], ['Consent Form Signed (Y/N)', 'consent'], ['Received Date', 'received_date'],
                ['Consultancy Received (1st/2nd/3rd)', '@consult_germany'], ['Documents checklist provided (Y/N)', 'docs_checklist'],
                ['Admission/Offer Received', '@offer_germany'], ['Embassy Status', 'embassy_status'], ['Student Contact Number', 'contact_no'],
                ['Level of Study', 'study_level'], ['Visa Status', 'visa'], ['Remarks Date', 'remarks_date'], ['Remarks', 'remarks'],
                ['Gmail', 'student_email'], ['Recovery Emails', 'recovery_email'],
            ]],
            'Summer GER Client 2027' => ['rows' => 'students', 'filter' => 'summer', 'cols' => [
                ['Sr. No', '@sr'], ['Student Name', 'name'], ['Dues', 'dues'], ['Counselor', 'counselor'], ['Refund Status', 'refund'],
                ['Branch', 'branch'], ['Educational Background', 'last_degree'], ['CGPA', 'gpa'], ['Drive Link', 'documents_link'],
                ['Intake', 'intake'], ['Consent Form Signed (Y/N)', 'consent'], ['Received Date', 'received_date'],
                ['Consultancy Received (1st/2nd/3rd)', '@consult_germany'], ['Documents checklist provided (Y/N)', 'docs_checklist'],
                ['Admission/Offer Received', '@offer_germany'], ['Embassy Status', 'embassy_status'], ['Student Contact Number', 'contact_no'],
                ['Level of Study', 'study_level'], ['Visa Status', 'visa'], ['Remarks Date', 'remarks_date'], ['Gmail Account', 'student_email'],
                ['Gmail Password', 'email_password_enc'], ['Uni Assist Account', 'portal_id'], ['Uni Assist Password', 'portal_password_enc'],
                ['Remarks', 'remarks'],
            ]],
            'Germany Centralized Sheet' => ['rows' => 'students', 'cols' => [
                ['Sr No.', '@row'], ['Student Name', 'name'], ['Counselor', 'counselor'], ['Refund Status', 'refund'], ['Branch', 'branch'],
                ['Reg Date', 'received_date'], ['Consent Form (Yes/No)', 'consent'], ['Last Degree', 'last_degree'], ['GPA', 'gpa'],
                ['Intake', 'intake'], ['Docu. Status', 'documents_status'], ['Pending Docu. Name', 'pending_documents'],
                ['Docu. Drive Link', 'documents_link'], ['Email Id', 'student_email'], ['Password', 'email_password_enc'],
                ['Recovery Email', 'recovery_email'], ['Admission Status', 'application_status'], ['Remarks', 'back_remarks'],
                ['Last Contacted Date', 'back_remarks_date'],
            ]],
            'Germany Clients' => ['rows' => 'students', 'cols' => [
                ['', '@row'], ['Ref', 'counselor'], ['Name', 'name'], ['Intake', 'intake'], ['Dues', 'dues'], ['DATE', 'received_date'],
                ['Contact Num', 'contact_no'], ['Level', 'study_level'], ['Background', 'last_degree'], ['CGPA', 'gpa'],
                ['Consent Form', '@consent_tick'], ['Date', 'remarks_date'], ['Admission Letter', 'admission_letters'], ['Remarks', 'remarks'],
                ['Recovery Email', 'recovery_email'],
            ]],
            // The university lists put each student's name on a row of its own above that student's universities.
            'Germany  Winter 2026' => ['rows' => 'apps', 'filter' => 'winter', 'group_col' => 0, 'cols' => [
                ['', '@app_no'], ['University', 'a:university'], ['Program', 'a:program'],
                ['Application Type', 'a:app_type'], ['Deadline', 'a:deadline'], ['Previous Degree', 'last_degree'],
                ['Language', 'a:requirements'], ['Additional Requirements', 'a:notes'], ['Gmail', 'student_email'],
                ['Acceptance', 'a:result'], ['Password', '@blank'], ['Portal ID', 'a:portal_id'], ['Status', 'a:status'],
                ['Submitted by', 'a:submitted_by'],
            ]],
            'Germany Summer 2027' => ['rows' => 'apps', 'filter' => 'summer', 'group_col' => 1, 'cols' => [
                ['Sr.No', '@app_no'], ['University', 'a:university'], ['Program', 'a:program'],
                ['Application Type', 'a:app_type'], ['Deadline', 'a:deadline'], ['Language', 'a:requirements'],
                ['Gmail', 'student_email'], ['Uni-Assist Account', 'portal_id'], ['Application Status', 'a:status'],
                ['Portal ID', 'a:portal_id'], ['Password', '@blank'],
            ]],
        ],
        default => [],
    };
}

/** Field keys that a country's sheet columns use (for coverage checks and tests). */
function sheet_field_keys(string $c): array {
    $keys = [];
    foreach (country_sheet_tabs($c) as $tab) foreach ($tab['cols'] as [, $src]) {
        if ($src[0] !== '@' && !str_starts_with($src, 'a:')) $keys[$src] = true;
    }
    return array_keys($keys);
}

// -------------------------------------------------------------------------------------
// Derived values: one stored field, several sheet columns.
// -------------------------------------------------------------------------------------

/** Was this meeting held? Accepts the older sheet wording too. */
function meeting_held(?string $v): bool {
    $l = strtolower(trim((string)$v));
    return $l === 'yes' || $l === 'meeting on call' || $l === 'meeting in office';
}

/** Offer received, from the application status (all countries) or the older Yes/No column. */
function offer_received(array $r): ?bool {
    $s = strtolower(trim((string)($r['application_status'] ?? '')));
    if ($s !== '') return (bool)preg_match('/^(offer received|received|accepted)/', $s);
    $o = strtolower(trim((string)($r['offer'] ?? '')));
    return $o === '' ? null : $o === 'yes';
}

/** Enrollment summary received, from the back-end status / date or the older front-end Yes/No column. */
function enrollment_received(array $r): ?bool {
    $s = strtolower(trim((string)($r['enrollment_summary_status'] ?? '')));
    if (!empty($r['enrollment_summary_date']) || str_starts_with($s, 'received')) return true;
    if ($s !== '') return false;
    $e = strtolower(trim((string)($r['enrollment'] ?? '')));
    return $e === '' ? null : $e === 'yes';
}

/** Keeps derived columns in step with the fields that are actually asked (called on every save). */
function sync_derived_fields(array &$data, array $merged): void {
    if (array_key_exists('application_status', $data)) {
        $o = offer_received(['application_status' => $data['application_status'], 'offer' => $merged['offer'] ?? null]);
        if ($o !== null) $data['offer'] = $o ? 'Yes' : 'No';
    }
    if (array_key_exists('enrollment_summary_status', $data) || array_key_exists('enrollment_summary_date', $data)) {
        $e = enrollment_received(array_merge($merged, $data));
        if ($e !== null) $data['enrollment'] = $e ? 'Yes' : 'No';
    }
    // A face-to-face meeting has no recording: write the sheet's own wording for that case.
    $m = array_merge($merged, $data);
    if ((array_key_exists('initial_meeting_mode', $data) || array_key_exists('initial_meeting', $data))
        && ($m['initial_meeting_mode'] ?? '') === 'Face-to-Face' && is_yes($m['initial_meeting'] ?? '')
        && in_array(strtolower(trim((string)($m['recorded_uploaded'] ?? ''))), ['', 'no'], true)) {
        $data['recorded_uploaded'] = 'Yes, face to face';
    }
}
