<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { formatDateTime } from '../../Utils/datetime';

/**
 * The print-ready CS Form No. 212 (Revised 2026): the four official
 * pages with the form's own row counts, then continuation sheets (C5–C11)
 * for whatever doesn't fit — as the form itself instructs. Print or "Save
 * as PDF" from the browser. No app layout: just the form.
 *
 * `signature` is null for the current (unsigned) PDS, or who e-signed a
 * submission and when.
 */
const props = defineProps({
    schema: { type: Object, required: true },
    pds: { type: Object, required: true },
    photoUrl: { type: String, default: null },
    signature: { type: Object, default: null },
});

const id = computed(() => props.pds.identity);
const s = computed(() => props.pds.sections);
const p = computed(() => s.value.personal);
const f = computed(() => s.value.family);
const q = computed(() => s.value.questions);
const g = computed(() => s.value.government_id);

// ── Formatting ─────────────────────────────────────────────────────
const NA = 'N/A';
const v = (value) => (value === null || value === undefined || value === '' ? '' : String(value));
const upper = (value) => v(value).toUpperCase();

/** dd/mm/yyyy, as the form asks. */
const dmy = (value) => {
    if (!value) return '';
    const [y, m, d] = value.slice(0, 10).split('-');
    return `${d}/${m}/${y}`;
};

const optionLabel = (section, key, value) =>
    props.schema.sections[section].fields.find((field) => field.key === key)?.options?.find((option) => option.value === value)?.label ?? v(value);

const box = (checked) => (checked ? '☒' : '☐');

// ── Splitting lists between the form and its continuation sheets ───
const split = (rows, onForm) => ({ form: [...rows.slice(0, onForm), ...Array(Math.max(0, onForm - rows.length)).fill(null)], more: rows.slice(onForm) });
const pad = (rows, size) => [...rows, ...Array(Math.max(0, size - rows.length)).fill(null)];

const children = computed(() => split(s.value.children, 13));
const eligibility = computed(() => split(s.value.eligibility, 7));
const work = computed(() => split(s.value.work_experience, 24));
const learning = computed(() => split(s.value.learning_development, 17));
const voluntary = computed(() => split(s.value.voluntary_work, 9));

// The form has one row per education level; extra schools continue (C8).
const LEVELS = ['elementary', 'secondary', 'vocational', 'college', 'graduate'];
const education = computed(() => {
    const first = {};
    const more = [];
    for (const row of s.value.education) {
        if (!first[row.level]) first[row.level] = row;
        else more.push(row);
    }
    return { form: LEVELS.map((level) => ({ level, row: first[level] ?? null })), more };
});

// VIII. Three columns side by side, 7 rows on the form (C11 for more).
const other = computed(() => {
    const columns = [s.value.skills, s.value.distinctions, s.value.memberships];
    const rows = (count, from) => Array.from({ length: count }, (_, i) => columns.map((column) => column[from + i]?.detail ?? ''));
    const extra = Math.max(...columns.map((column) => column.length)) - 7;
    return { form: rows(7, 0), more: extra > 0 ? rows(extra, 7) : [] };
});

const references = computed(() => pad(s.value.references, 3));

const address = (kind) => {
    const source = kind === 'permanent' && p.value.permanent_same_as_residential ? 'residential' : kind;
    const part = (key) => v(p.value[`${source}_${key}`]);
    return { house: part('house_no'), street: part('street'), subdivision: part('subdivision'), barangay: part('barangay'), city: part('city'), province: part('province'), zip: part('zip_code') };
};

const yesNo = (key) => q.value[key];

const signedOn = computed(() => (props.signature ? dmy(props.signature.signed_at.slice(0, 10)) : ''));
const signatureText = computed(() =>
    props.signature ? `Electronically signed through IMIS by ${props.signature.name}` : 'Not signed — current PDS (draft)',
);
const printedAt = new Date().toISOString();

const print = () => window.print();
</script>

<template>
    <Head title="Personal Data Sheet — print" />

    <div class="pds-toolbar">
        <p>
            <strong>CS Form No. 212 (Revised 2026)</strong> — {{ signature ? `${signature.status}, signed ${formatDateTime(signature.signed_at)}` : 'current PDS, not signed' }}.
            Use your browser's print dialog; choose “Save as PDF” to keep a copy.
        </p>
        <button type="button" @click="print">Print</button>
    </div>

    <main class="pds">
        <!-- ═══════════════════════ PAGE 1 ═══════════════════════ -->
        <section class="sheet">
            <p class="form-no"><em>CS Form No. 212</em><br /><em>Revised 2026</em></p>
            <h1>PERSONAL DATA SHEET</h1>
            <p class="warning">
                WARNING: Any misrepresentation made in the Personal Data Sheet and the Work Experience Sheet shall cause the filing of
                administrative/criminal case/s against the person concerned.<br />
                READ THE ATTACHED GUIDE TO FILLING OUT THE PERSONAL DATA SHEET (PDS) BEFORE ACCOMPLISHING THE PDS FORM.
            </p>
            <p class="note">Print legibly. Tick appropriate boxes (☐) and use separate sheet if necessary. Indicate N/A if not applicable. DO NOT ABBREVIATE.</p>

            <table>
                <tr><th colspan="6" class="section">I. PERSONAL INFORMATION</th></tr>
                <tr><td class="label">1. SURNAME</td><td colspan="5" class="value">{{ upper(id.surname) }}</td></tr>
                <tr>
                    <td class="label">2. FIRST NAME</td><td colspan="3" class="value">{{ upper(id.first_name) }}</td>
                    <td class="label small">NAME EXTENSION (JR., SR)</td><td class="value">{{ upper(id.name_extension) || NA }}</td>
                </tr>
                <tr><td class="label">MIDDLE NAME</td><td colspan="5" class="value">{{ upper(id.middle_name) || NA }}</td></tr>
                <tr>
                    <td class="label">3. DATE OF BIRTH (dd/mm/yyyy)</td><td class="value">{{ dmy(id.date_of_birth) }}</td>
                    <td class="label" rowspan="3">16. CITIZENSHIP<br /><span class="small">If holder of dual citizenship, please indicate the details.</span></td>
                    <td colspan="3" class="value">
                        {{ box(p.citizenship === 'filipino') }} Filipino &nbsp; {{ box(p.citizenship === 'dual') }} Dual Citizenship
                        <template v-if="p.citizenship === 'dual'"> &nbsp; {{ box(p.dual_citizenship_by === 'birth') }} by birth {{ box(p.dual_citizenship_by === 'naturalization') }} by naturalization</template>
                    </td>
                </tr>
                <tr>
                    <td class="label">4. PLACE OF BIRTH</td><td class="value">{{ v(p.place_of_birth) }}</td>
                    <td colspan="3" class="value">Pls. indicate country: {{ v(p.dual_citizenship_country) }}</td>
                </tr>
                <tr>
                    <td class="label">5. SEX AT BIRTH</td>
                    <td class="value">{{ box(id.sex_at_birth === 'male') }} Male &nbsp; {{ box(id.sex_at_birth === 'female') }} Female</td>
                    <td colspan="3"></td>
                </tr>
                <tr>
                    <td class="label">6. CIVIL STATUS</td>
                    <td class="value small">
                        {{ box(p.civil_status === 'single') }} Single {{ box(p.civil_status === 'married') }} Married<br />
                        {{ box(p.civil_status === 'widowed') }} Widowed {{ box(p.civil_status === 'separated') }} Separated<br />
                        {{ box(p.civil_status === 'other') }} Other/s: {{ v(p.civil_status_other) }}
                    </td>
                    <td class="label" rowspan="3">17. RESIDENTIAL ADDRESS</td>
                    <td colspan="3" class="value">
                        <div class="addr">
                        <span>{{ address('residential').house }}<small>House/Block/Lot No.</small></span>
                        <span>{{ address('residential').street }}<small>Street</small></span>
                        <span>{{ address('residential').subdivision }}<small>Subdivision/Village</small></span>
                        <span>{{ address('residential').barangay }}<small>Barangay</small></span>
                        <span>{{ address('residential').city }}<small>City/Municipality</small></span>
                        <span>{{ address('residential').province }}<small>Province</small></span>
                    </div>
                    </td>
                </tr>
                <tr><td class="label">7. HEIGHT (m)</td><td class="value">{{ v(p.height_m) }}</td><td colspan="3" class="value">ZIP CODE: {{ address('residential').zip }}</td></tr>
                <tr><td class="label">8. WEIGHT (kg)</td><td class="value">{{ v(p.weight_kg) }}</td><td colspan="3"></td></tr>
                <tr>
                    <td class="label">9. BLOOD TYPE</td><td class="value">{{ v(p.blood_type) }}</td>
                    <td class="label" rowspan="3">18. PERMANENT ADDRESS</td>
                    <td colspan="3" class="value" rowspan="2">
                        <div class="addr">
                        <span>{{ address('permanent').house }}<small>House/Block/Lot No.</small></span>
                        <span>{{ address('permanent').street }}<small>Street</small></span>
                        <span>{{ address('permanent').subdivision }}<small>Subdivision/Village</small></span>
                        <span>{{ address('permanent').barangay }}<small>Barangay</small></span>
                        <span>{{ address('permanent').city }}<small>City/Municipality</small></span>
                        <span>{{ address('permanent').province }}<small>Province</small></span>
                    </div>
                    </td>
                </tr>
                <tr><td class="label">10. UMID ID NO.</td><td class="value">{{ v(p.umid_no) }}</td></tr>
                <tr><td class="label">11. PAG-IBIG ID NO.</td><td class="value">{{ v(p.pagibig_no) }}</td><td colspan="3" class="value">ZIP CODE: {{ address('permanent').zip }}</td></tr>
                <tr><td class="label">12. PHILHEALTH NO.</td><td class="value">{{ v(p.philhealth_no) }}</td><td class="label">19. TELEPHONE NO.</td><td colspan="3" class="value">{{ v(p.telephone_no) }}</td></tr>
                <tr><td class="label">13. PhilSys Card Number (PCN)</td><td class="value">{{ v(p.philsys_no) }}</td><td class="label">20. MOBILE NO.</td><td colspan="3" class="value">{{ v(p.mobile_no) }}</td></tr>
                <tr><td class="label">14. TIN NO.</td><td class="value">{{ v(p.tin_no) }}</td><td class="label">21. E-MAIL ADDRESS (if any)</td><td colspan="3" class="value">{{ v(p.email) }}</td></tr>
                <tr><td class="label">15. AGENCY EMPLOYEE NO.</td><td class="value">{{ v(id.agency_employee_no) }}</td><td colspan="4"></td></tr>
            </table>

            <table>
                <tr><th colspan="6" class="section">II. FAMILY BACKGROUND</th></tr>
                <tr>
                    <td class="label">22. SPOUSE'S SURNAME</td><td colspan="2" class="value">{{ upper(f.spouse_surname) }}</td>
                    <td class="label" colspan="2">23. NAME of CHILDREN (Write full name and list all)</td><td class="label">DATE OF BIRTH (dd/mm/yyyy)</td>
                </tr>
                <tr v-for="(line, i) in [
                    ['FIRST NAME', upper(f.spouse_first_name) + (f.spouse_name_extension ? ' ' + upper(f.spouse_name_extension) : '')],
                    ['MIDDLE NAME', upper(f.spouse_middle_name)],
                    ['OCCUPATION', v(f.spouse_occupation)],
                    ['EMPLOYER/BUSINESS NAME', v(f.spouse_employer)],
                    ['BUSINESS ADDRESS', v(f.spouse_business_address)],
                    ['TELEPHONE NO.', v(f.spouse_telephone_no)],
                    [`24. FATHER'S SURNAME`, upper(f.father_surname)],
                    ['FIRST NAME', upper(f.father_first_name) + (f.father_name_extension ? ' ' + upper(f.father_name_extension) : '')],
                    ['MIDDLE NAME', upper(f.father_middle_name)],
                    [`25. MOTHER'S MAIDEN NAME — SURNAME`, upper(f.mother_surname)],
                    ['FIRST NAME', upper(f.mother_first_name)],
                    ['MIDDLE NAME', upper(f.mother_middle_name)],
                ]" :key="i">
                    <td class="label">{{ line[0] }}</td><td colspan="2" class="value">{{ line[1] }}</td>
                    <td colspan="2" class="value">{{ children.form[i]?.full_name ?? '' }}</td><td class="value">{{ dmy(children.form[i]?.date_of_birth) }}</td>
                </tr>
                <tr><td colspan="3"></td><td colspan="2" class="value">{{ children.form[12]?.full_name ?? '' }}</td><td class="value">{{ dmy(children.form[12]?.date_of_birth) }}</td></tr>
                <tr v-if="children.more.length"><td colspan="6" class="continue">(Continued on sheet C7)</td></tr>
            </table>

            <table>
                <tr><th colspan="8" class="section">III. EDUCATIONAL BACKGROUND</th></tr>
                <tr class="head">
                    <td>26. LEVEL</td><td>NAME OF SCHOOL (Write in full)</td><td>BASIC EDUCATION/DEGREE/COURSE (Write in full)</td>
                    <td>PERIOD OF ATTENDANCE From</td><td>To</td><td>HIGHEST LEVEL/ UNITS EARNED (if not graduated)</td><td>YEAR GRADUATED</td><td>SCHOLARSHIP/ ACADEMIC HONORS RECEIVED</td>
                </tr>
                <tr v-for="{ level, row } in education.form" :key="level">
                    <td class="label">{{ optionLabel('education', 'level', level).toUpperCase() }}</td>
                    <td class="value">{{ v(row?.school) }}</td><td class="value">{{ v(row?.degree) }}</td>
                    <td class="value c">{{ v(row?.attendance_from) }}</td><td class="value c">{{ v(row?.attendance_to) }}</td>
                    <td class="value">{{ v(row?.highest_level_units) }}</td><td class="value c">{{ v(row?.year_graduated) }}</td><td class="value">{{ v(row?.honors) }}</td>
                </tr>
                <tr v-if="education.more.length"><td colspan="8" class="continue">(Continued on sheet C8)</td></tr>
            </table>

            <div class="sign"><div>SIGNATURE <span>{{ signatureText }}</span></div><div>DATE <span>{{ signedOn }}</span></div></div>
            <p class="page-no">CS FORM 212 (Revised 2026), Page 1 of 4</p>
        </section>

        <!-- ═══════════════════════ PAGE 2 ═══════════════════════ -->
        <section class="sheet">
            <table>
                <tr><th colspan="6" class="section">IV. CIVIL SERVICE ELIGIBILITY</th></tr>
                <tr class="head">
                    <td>27. CAREER SERVICE/ RA 1080 (BOARD/ BAR) UNDER SPECIAL LAWS/ CES/ CSEE BARANGAY ELIGIBILITY / DRIVER'S LICENSE</td>
                    <td>RATING (If Applicable)</td><td>DATE OF EXAMINATION / CONFERMENT</td><td>PLACE OF EXAMINATION / CONFERMENT</td>
                    <td>LICENSE NUMBER</td><td>Date of Validity</td>
                </tr>
                <tr v-for="(row, i) in eligibility.form" :key="i">
                    <td class="value">{{ v(row?.eligibility) }}</td><td class="value c">{{ v(row?.rating) }}</td><td class="value c">{{ dmy(row?.exam_date) }}</td>
                    <td class="value">{{ v(row?.exam_place) }}</td><td class="value c">{{ v(row?.license_number) }}</td><td class="value c">{{ dmy(row?.license_valid_until) }}</td>
                </tr>
                <tr v-if="eligibility.more.length"><td colspan="6" class="continue">(Continued on sheet C9)</td></tr>
            </table>

            <table>
                <tr><th colspan="8" class="section">V. WORK EXPERIENCE</th></tr>
                <tr><td colspan="8" class="note">(Include private employment. Start from your recent work) Description of duties should be indicated in the attached Work Experience sheet.</td></tr>
                <tr class="head">
                    <td>28. INCLUSIVE DATES From</td><td>To</td><td>POSITION TITLE (Write in full/Do not abbreviate)</td>
                    <td>DEPARTMENT / AGENCY / OFFICE / COMPANY (Write in full/Do not abbreviate)</td><td>MONTHLY SALARY</td>
                    <td>SALARY/ JOB/ PAY GRADE &amp; STEP (Format "00-0")</td><td>STATUS OF APPOINTMENT</td><td>GOV'T SERVICE (Y/ N)</td>
                </tr>
                <tr v-for="(row, i) in work.form" :key="i">
                    <td class="value c">{{ dmy(row?.date_from) }}</td><td class="value c">{{ row ? (row.date_to ? dmy(row.date_to) : 'PRESENT') : '' }}</td>
                    <td class="value">{{ v(row?.position_title) }}</td><td class="value">{{ v(row?.agency) }}</td>
                    <td class="value r">{{ v(row?.monthly_salary) }}</td><td class="value c">{{ v(row?.salary_grade) }}</td>
                    <td class="value">{{ v(row?.appointment_status) }}</td><td class="value c">{{ row ? (row.is_government ? 'Y' : 'N') : '' }}</td>
                </tr>
                <tr v-if="work.more.length"><td colspan="8" class="continue">(Continued on sheet C6)</td></tr>
            </table>

            <div class="sign"><div>SIGNATURE <span>{{ signatureText }}</span></div><div>DATE <span>{{ signedOn }}</span></div></div>
            <p class="page-no">CS FORM 212 (Revised 2026), Page 2 of 4</p>
        </section>

        <!-- ═══════════════════════ PAGE 3 ═══════════════════════ -->
        <section class="sheet">
            <table>
                <tr><th colspan="6" class="section">VI. LEARNING AND DEVELOPMENT (L&amp;D) INTERVENTIONS/TRAINING PROGRAMS ATTENDED</th></tr>
                <tr class="head">
                    <td>29. TITLE OF LEARNING AND DEVELOPMENT INTERVENTIONS/TRAINING PROGRAMS (Write in full)</td>
                    <td>INCLUSIVE DATES From</td><td>To</td><td>NUMBER OF HOURS</td><td>Type of L&amp;D (Managerial/ Supervisory/ Technical/etc)</td><td>CONDUCTED/ SPONSORED BY (Write in full)</td>
                </tr>
                <tr v-for="(row, i) in learning.form" :key="i">
                    <td class="value">{{ v(row?.title) }}</td><td class="value c">{{ dmy(row?.date_from) }}</td><td class="value c">{{ dmy(row?.date_to) }}</td>
                    <td class="value c">{{ v(row?.hours) }}</td><td class="value">{{ v(row?.ld_type) }}</td><td class="value">{{ v(row?.sponsor) }}</td>
                </tr>
                <tr v-if="learning.more.length"><td colspan="6" class="continue">(Continued on sheet C5)</td></tr>
            </table>

            <table>
                <tr><th colspan="5" class="section">VII. VOLUNTARY WORK OR INVOLVEMENT IN CIVIC / NON-GOVERNMENT / PEOPLE / VOLUNTARY ORGANIZATION/S</th></tr>
                <tr class="head">
                    <td>30. NAME &amp; ADDRESS OF ORGANIZATION (Write in full)</td><td>INCLUSIVE DATES From</td><td>To</td><td>NUMBER OF HOURS</td><td>POSITION / NATURE OF WORK</td>
                </tr>
                <tr v-for="(row, i) in voluntary.form" :key="i">
                    <td class="value">{{ v(row?.organization) }}</td><td class="value c">{{ dmy(row?.date_from) }}</td><td class="value c">{{ dmy(row?.date_to) }}</td>
                    <td class="value c">{{ v(row?.hours) }}</td><td class="value">{{ v(row?.position) }}</td>
                </tr>
                <tr v-if="voluntary.more.length"><td colspan="5" class="continue">(Continued on sheet C10)</td></tr>
            </table>

            <table>
                <tr><th colspan="3" class="section">VIII. OTHER INFORMATION</th></tr>
                <tr class="head">
                    <td>31. SPECIAL SKILLS and HOBBIES</td><td>32. NON-ACADEMIC DISTINCTIONS / RECOGNITION (Write in full)</td><td>33. MEMBERSHIP IN ASSOCIATION/ORGANIZATION (Write in full)</td>
                </tr>
                <tr v-for="(row, i) in other.form" :key="i"><td v-for="(cell, c) in row" :key="c" class="value">{{ cell }}</td></tr>
                <tr v-if="other.more.length"><td colspan="3" class="continue">(Continued on sheet C11)</td></tr>
            </table>

            <div class="sign"><div>SIGNATURE <span>{{ signatureText }}</span></div><div>DATE <span>{{ signedOn }}</span></div></div>
            <p class="page-no">CS FORM 212 (Revised 2026), Page 3 of 4</p>
        </section>

        <!-- ═══════════════════════ PAGE 4 ═══════════════════════ -->
        <section class="sheet">
            <table class="questions">
                <tr v-for="item in [
                    { n: '34.', text: 'Are you related by consanguinity or affinity to the appointing or recommending authority, or to the chief of bureau or office or to the person who has immediate supervision over you in the Office, Bureau or Department where you will be appointed,' },
                    { n: '', text: 'a. within the third degree?', key: 'q34a' },
                    { n: '', text: 'b. within the fourth degree (for Local Government Unit - Career Employees)?', key: 'q34b', details: q.q34_details },
                    { n: '35.', text: 'a. Have you ever been found guilty of any administrative offense?', key: 'q35a', details: q.q35a_details },
                    { n: '', text: 'b. Have you been criminally charged before any court?', key: 'q35b', details: q.q35b_details, extra: q.q35b ? `Date Filed: ${dmy(q.q35b_date_filed)}   Status of Case/s: ${v(q.q35b_case_status)}` : '' },
                    { n: '36.', text: 'Have you ever been convicted of any crime or violation of any law, decree, ordinance or regulation by any court or tribunal?', key: 'q36', details: q.q36_details },
                    { n: '37.', text: 'Have you ever been separated from the service in any of the following modes: resignation, retirement, dropped from the rolls, dismissal, termination, end of term, finished contract or phased out (abolition) in the public or private sector?', key: 'q37', details: q.q37_details },
                    { n: '38.', text: 'a. Have you ever been a candidate in a national or local election held within the last year (except Barangay election)?', key: 'q38a', details: q.q38a_details },
                    { n: '', text: 'b. Have you resigned from the government service during the three (3)-month period before the last election to promote/actively campaign for a national or local candidate?', key: 'q38b', details: q.q38b_details },
                    { n: '39.', text: 'Have you acquired the status of an immigrant or permanent resident of another country?', key: 'q39', details: q.q39_country, detailsLabel: 'If YES, give details (country):' },
                    { n: '40.', text: 'Pursuant to: (a) Indigenous People\'s Act (RA 8371); (b) Magna Carta for Disabled Persons (RA 7277, as amended); and (c) Expanded Solo Parents Welfare Act (RA 11861), please answer the following items:' },
                    { n: 'a.', text: 'Are you a member of any indigenous group?', key: 'q40a', details: q.q40a_group, detailsLabel: 'If YES, please specify:' },
                    { n: 'b.', text: 'Are you a person with disability?', key: 'q40b', details: q.q40b_id, detailsLabel: 'If YES, please specify ID No:' },
                    { n: 'c.', text: 'Are you a solo parent?', key: 'q40c', details: q.q40c_id, detailsLabel: 'If YES, please specify ID No:' },
                ]" :key="item.text">
                    <td class="qn">{{ item.n }}</td>
                    <td class="label qtext">{{ item.text }}</td>
                    <td class="value answer">
                        <template v-if="item.key">
                            {{ box(yesNo(item.key) === true) }} YES &nbsp; {{ box(yesNo(item.key) === false) }} NO
                            <div v-if="item.details" class="details">{{ item.detailsLabel ?? 'If YES, give details:' }} {{ item.details }}</div>
                            <div v-if="item.extra" class="details">{{ item.extra }}</div>
                        </template>
                    </td>
                </tr>
            </table>

            <table>
                <tr><td colspan="3" class="label">41. REFERENCES (Person not related by consanguinity or affinity to applicant /appointee)</td><td rowspan="5" class="photo">
                    <img v-if="photoUrl" :src="photoUrl" alt="" />
                    <span v-else>ID picture taken within the last 6 months<br />3.5 cm. X 4.5 cm<br />(passport size)</span>
                    <small>PHOTO</small>
                </td></tr>
                <tr class="head"><td>NAME</td><td>OFFICE / RESIDENTIAL ADDRESS</td><td>CONTACT NO. AND/OR EMAIL</td></tr>
                <tr v-for="(row, i) in references" :key="i"><td class="value">{{ v(row?.name) }}</td><td class="value">{{ v(row?.address) }}</td><td class="value">{{ v(row?.contact) }}</td></tr>
            </table>

            <table>
                <tr><td colspan="3" class="declaration">
                    42. I declare under oath that I have personally accomplished this Personal Data Sheet which is a true, correct, and complete statement
                    pursuant to the provisions of pertinent laws, rules, and regulations of the Republic of the Philippines. I authorize the agency
                    head/authorized representative to verify/validate the contents stated herein. I agree that any misrepresentation made in this
                    document and its attachments shall cause the filing of administrative/criminal case/s against me.
                </td></tr>
                <tr>
                    <td class="gov-id">
                        <p class="label">Government Issued ID (i.e.Passport, GSIS, SSS, PRC, Driver's License, etc.) PLEASE INDICATE ID Number and Date of Issuance</p>
                        <p>Government Issued ID: <strong>{{ v(g.government_id_type) }}</strong></p>
                        <p>ID/License/Passport No.: <strong>{{ v(g.government_id_number) }}</strong></p>
                        <p>Date/Place of Issuance: <strong>{{ v(g.government_id_issuance) }}</strong></p>
                    </td>
                    <td class="signature-box">
                        <div class="esign">{{ signatureText }}</div>
                        <small>Signature (Sign inside the box)</small>
                        <div class="esign">{{ signedOn }}</div>
                        <small>Date Accomplished</small>
                    </td>
                    <td class="thumb"><div></div><small>Right Thumbmark</small></td>
                </tr>
                <tr><td colspan="3" class="oath">
                    SUBSCRIBED AND SWORN to before me this ______________, affiant exhibiting his/her validly issued government ID as indicated above.
                    <div class="administering"><span></span><small>Person Administering Oath</small></div>
                </td></tr>
            </table>
            <p class="page-no">CS FORM 212 (Revised 2026), Page 4 of 4</p>
        </section>

        <!-- ═══════════════════ CONTINUATION SHEETS ═══════════════════ -->
        <section v-if="learning.more.length" class="sheet">
            <table>
                <tr><th colspan="6" class="section">LEARNING AND DEVELOPMENT (L&amp;D) INTERVENTIONS/TRAINING PROGRAMS ATTENDED (Continuation)</th></tr>
                <tr class="head"><td>TITLE (Write in full)</td><td>From</td><td>To</td><td>NUMBER OF HOURS</td><td>Type of L&amp;D</td><td>CONDUCTED/ SPONSORED BY</td></tr>
                <tr v-for="(row, i) in learning.more" :key="i">
                    <td class="value">{{ v(row.title) }}</td><td class="value c">{{ dmy(row.date_from) }}</td><td class="value c">{{ dmy(row.date_to) }}</td>
                    <td class="value c">{{ v(row.hours) }}</td><td class="value">{{ v(row.ld_type) }}</td><td class="value">{{ v(row.sponsor) }}</td>
                </tr>
            </table>
            <div class="sign"><div>SIGNATURE <span>{{ signatureText }}</span></div><div>DATE <span>{{ signedOn }}</span></div></div>
            <p class="page-no">CS FORM 212 (Revised 2026) — C5</p>
        </section>

        <section v-if="work.more.length" class="sheet">
            <table>
                <tr><th colspan="8" class="section">WORK EXPERIENCE (Continuation)</th></tr>
                <tr class="head"><td>From</td><td>To</td><td>POSITION TITLE</td><td>DEPARTMENT / AGENCY / OFFICE / COMPANY</td><td>MONTHLY SALARY</td><td>SG &amp; STEP</td><td>STATUS OF APPOINTMENT</td><td>GOV'T (Y/N)</td></tr>
                <tr v-for="(row, i) in work.more" :key="i">
                    <td class="value c">{{ dmy(row.date_from) }}</td><td class="value c">{{ row.date_to ? dmy(row.date_to) : 'PRESENT' }}</td>
                    <td class="value">{{ v(row.position_title) }}</td><td class="value">{{ v(row.agency) }}</td><td class="value r">{{ v(row.monthly_salary) }}</td>
                    <td class="value c">{{ v(row.salary_grade) }}</td><td class="value">{{ v(row.appointment_status) }}</td><td class="value c">{{ row.is_government ? 'Y' : 'N' }}</td>
                </tr>
            </table>
            <div class="sign"><div>SIGNATURE <span>{{ signatureText }}</span></div><div>DATE <span>{{ signedOn }}</span></div></div>
            <p class="page-no">CS FORM 212 (Revised 2026) — C6</p>
        </section>

        <section v-if="children.more.length" class="sheet">
            <table>
                <tr><th colspan="2" class="section">FAMILY BACKGROUND (Continuation)</th></tr>
                <tr class="head"><td>NAME of CHILDREN (Write full name and list all)</td><td>DATE OF BIRTH (dd/mm/yyyy)</td></tr>
                <tr v-for="(row, i) in children.more" :key="i"><td class="value">{{ v(row.full_name) }}</td><td class="value c">{{ dmy(row.date_of_birth) }}</td></tr>
            </table>
            <div class="sign"><div>SIGNATURE <span>{{ signatureText }}</span></div><div>DATE <span>{{ signedOn }}</span></div></div>
            <p class="page-no">CS FORM 212 (Revised 2026) — C7</p>
        </section>

        <section v-if="education.more.length" class="sheet">
            <table>
                <tr><th colspan="8" class="section">EDUCATIONAL BACKGROUND (Continuation)</th></tr>
                <tr class="head"><td>LEVEL</td><td>NAME OF SCHOOL</td><td>DEGREE/COURSE</td><td>From</td><td>To</td><td>HIGHEST LEVEL/ UNITS EARNED</td><td>YEAR GRADUATED</td><td>HONORS</td></tr>
                <tr v-for="(row, i) in education.more" :key="i">
                    <td class="label">{{ optionLabel('education', 'level', row.level).toUpperCase() }}</td><td class="value">{{ v(row.school) }}</td><td class="value">{{ v(row.degree) }}</td>
                    <td class="value c">{{ v(row.attendance_from) }}</td><td class="value c">{{ v(row.attendance_to) }}</td><td class="value">{{ v(row.highest_level_units) }}</td>
                    <td class="value c">{{ v(row.year_graduated) }}</td><td class="value">{{ v(row.honors) }}</td>
                </tr>
            </table>
            <div class="sign"><div>SIGNATURE <span>{{ signatureText }}</span></div><div>DATE <span>{{ signedOn }}</span></div></div>
            <p class="page-no">CS FORM 212 (Revised 2026) — C8</p>
        </section>

        <section v-if="eligibility.more.length" class="sheet">
            <table>
                <tr><th colspan="6" class="section">CIVIL SERVICE ELIGIBILITY (Continuation)</th></tr>
                <tr class="head"><td>CES/CSEE/CAREER SERVICE/RA 1080 (BOARD/ BAR)/UNDER SPECIAL LAWS/CATEGORY II/ IV ELIGIBILITY and ELIGIBILITIES FOR UNIFORMED PERSONNEL</td><td>RATING</td><td>DATE OF EXAMINATION / CONFERMENT</td><td>PLACE OF EXAMINATION / CONFERMENT</td><td>LICENSE NUMBER</td><td>Valid Until</td></tr>
                <tr v-for="(row, i) in eligibility.more" :key="i">
                    <td class="value">{{ v(row.eligibility) }}</td><td class="value c">{{ v(row.rating) }}</td><td class="value c">{{ dmy(row.exam_date) }}</td>
                    <td class="value">{{ v(row.exam_place) }}</td><td class="value c">{{ v(row.license_number) }}</td><td class="value c">{{ dmy(row.license_valid_until) }}</td>
                </tr>
            </table>
            <div class="sign"><div>SIGNATURE <span>{{ signatureText }}</span></div><div>DATE <span>{{ signedOn }}</span></div></div>
            <p class="page-no">CS FORM 212 (Revised 2026) — C9</p>
        </section>

        <section v-if="voluntary.more.length" class="sheet">
            <table>
                <tr><th colspan="5" class="section">VOLUNTARY WORK OR INVOLVEMENT IN CIVIC / NON-GOVERNMENT / PEOPLE / VOLUNTARY ORGANIZATION/S (Continuation)</th></tr>
                <tr class="head"><td>NAME &amp; ADDRESS OF ORGANIZATION</td><td>From</td><td>To</td><td>NUMBER OF HOURS</td><td>POSITION / NATURE OF WORK</td></tr>
                <tr v-for="(row, i) in voluntary.more" :key="i">
                    <td class="value">{{ v(row.organization) }}</td><td class="value c">{{ dmy(row.date_from) }}</td><td class="value c">{{ dmy(row.date_to) }}</td>
                    <td class="value c">{{ v(row.hours) }}</td><td class="value">{{ v(row.position) }}</td>
                </tr>
            </table>
            <div class="sign"><div>SIGNATURE <span>{{ signatureText }}</span></div><div>DATE <span>{{ signedOn }}</span></div></div>
            <p class="page-no">CS FORM 212 (Revised 2026) — C10</p>
        </section>

        <section v-if="other.more.length" class="sheet">
            <table>
                <tr><th colspan="3" class="section">OTHER INFORMATION (Continuation)</th></tr>
                <tr class="head"><td>SPECIAL SKILLS and HOBBIES</td><td>NON-ACADEMIC DISTINCTIONS / RECOGNITION</td><td>MEMBERSHIP IN ASSOCIATION/ORGANIZATION</td></tr>
                <tr v-for="(row, i) in other.more" :key="i"><td v-for="(cell, c) in row" :key="c" class="value">{{ cell }}</td></tr>
            </table>
            <div class="sign"><div>SIGNATURE <span>{{ signatureText }}</span></div><div>DATE <span>{{ signedOn }}</span></div></div>
            <p class="page-no">CS FORM 212 (Revised 2026) — C11</p>
        </section>

        <p class="printed">Printed from IMIS {{ formatDateTime(printedAt) }}</p>
    </main>
</template>

<style scoped>
/* The form's own look: black rules, grey label cells, dark section bars.
   Fixed print colours on purpose (this is a paper form, not the app UI). */
.pds {
    font-family: 'Arial Narrow', Arial, sans-serif;
    font-size: 7.5pt;
    color: #000;
    background: #9ca3af;
    padding: 1rem 0;
}

.sheet {
    background: #fff;
    width: 8.5in;
    min-height: 13in;
    margin: 0 auto 1rem;
    padding: 0.3in;
    box-sizing: border-box;
    box-shadow: 0 2px 8px rgb(0 0 0 / 0.3);
}

h1 { text-align: center; font-size: 15pt; font-weight: 900; margin: 0.05in 0; }
.form-no { font-size: 8pt; font-weight: 700; margin: 0; }
.warning { font-size: 6.5pt; font-weight: 700; font-style: italic; margin: 0 0 0.04in; }
.note { font-size: 6.5pt; font-style: italic; }

table { width: 100%; border-collapse: collapse; margin-bottom: 0.06in; table-layout: auto; }
td, th { border: 1px solid #000; padding: 1px 3px; vertical-align: middle; }
th.section { background: #767171; color: #fff; text-align: left; font-style: italic; font-weight: 700; font-size: 8pt; }
td.label, tr.head td { background: #eaeaea; font-size: 6.5pt; }
tr.head td { text-align: center; font-weight: 700; }
td.value { font-size: 7.5pt; height: 0.2in; }
td.c { text-align: center; }
td.r { text-align: right; }
.small { font-size: 6pt; }
td.continue { text-align: center; font-style: italic; font-size: 6.5pt; color: #c00; }

.addr { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0 4px; }
.addr span { display: flex; flex-direction: column; border-bottom: 1px solid #000; min-height: 0.22in; justify-content: flex-end; }
.addr small { font-size: 5.5pt; font-style: italic; text-align: center; border-top: 1px solid #000; }

.questions td.qn { width: 0.3in; background: #eaeaea; vertical-align: top; font-weight: 700; }
.questions td.qtext { width: 60%; vertical-align: top; }
.questions td.answer { vertical-align: top; }
.details { font-size: 6.5pt; margin-top: 2px; border-bottom: 1px solid #000; }

td.photo { width: 1.4in; text-align: center; vertical-align: middle; position: relative; }
td.photo img { width: 3.5cm; height: 4.5cm; object-fit: cover; display: block; margin: 0 auto; }
td.photo span { display: block; border: 1px solid #000; width: 3.5cm; height: 4.5cm; margin: 0 auto; font-size: 6pt; padding-top: 1.2cm; box-sizing: border-box; }
td.photo small, .signature-box small, .thumb small, .administering small { display: block; text-align: center; font-size: 6.5pt; background: #eaeaea; }

.declaration { font-size: 7pt; text-align: justify; }
.gov-id { width: 45%; vertical-align: top; }
.gov-id p { margin: 0 0 3px; }
.signature-box { vertical-align: bottom; text-align: center; }
.esign { min-height: 0.35in; display: flex; align-items: flex-end; justify-content: center; font-style: italic; font-size: 7pt; }
.thumb { width: 1.3in; vertical-align: bottom; }
.thumb div { height: 1.1in; }
.oath { font-size: 7pt; }
.administering { width: 3in; margin: 0.35in auto 0; }
.administering span { display: block; border-bottom: 1px solid #000; height: 0.2in; }

.sign { display: grid; grid-template-columns: 3fr 1fr; border: 1px solid #000; font-size: 7pt; font-weight: 700; font-style: italic; }
.sign > div { padding: 4px; border-right: 1px solid #000; }
.sign > div:last-child { border-right: 0; }
.sign span { font-weight: 400; margin-left: 0.2in; }
.page-no { text-align: right; font-size: 6.5pt; font-style: italic; margin-top: 2px; }
.printed { text-align: center; font-size: 7pt; color: #fff; }

.pds-toolbar {
    position: sticky;
    top: 0;
    z-index: 10;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: var(--color-csc-blue);
    color: #fff;
    font: 0.85rem system-ui, sans-serif;
}
.pds-toolbar button {
    background: #fff;
    color: var(--color-csc-blue);
    border-radius: 0.375rem;
    padding: 0.35rem 1rem;
    font-weight: 600;
}

@media print {
    @page { size: 8.5in 13in; margin: 0; }
    .pds-toolbar, .printed { display: none; }
    .pds { background: none; padding: 0; }
    .sheet { box-shadow: none; margin: 0; break-after: page; }
    .sheet:last-of-type { break-after: auto; }
}
</style>
