# UserCourseControl Moodle Plugin

**Plugin Name:** local_usercoursecontrol  
**Version:** 1.3.0  
**Moodle Installation Path:** `[moodleroot]/local/usercoursecontrol/`

Lightweight Moodle local plugin exposing custom Web Service APIs for:

- Grade modification history for all course grade items (assignments, quizzes, etc.)
- Listing suspended course enrolments for a user
- Reinstate (unsuspend) a user in a course via enrol plugin API
- Get user's enrolled courses with database-level filtering by course name
- Turnitin Direct V2 assignment management (get assignments, toggle late submissions)
- Standard Moodle assignment control (get assignments, set cutoff dates to restrict submissions)

No core changes. Uses only supported Moodle APIs and tables.

## 1. Plugin Overview

- Purpose: Provide REST/JSON Web Service endpoints for external systems to query individual grade items (assignments, quizzes, etc.) with modification history, list courses where the user is suspended, unsuspend a user without re-enrolling, efficiently fetch filtered course enrollments, manage Turnitin assignments, and control student submission access to standard Moodle assignments.
- Non-invasive: No schema changes, observers, events, cron, UI pages, or settings.
- Data sources: grade_grades, grade_grades_history, grade_items, enrol, user_enrolments, course, turnitintooltwo, turnitintooltwo_parts, assign, course_modules.
- Security: Strict parameter validation, capability checks, and exceptions for invalid states.
- Filters out deleted/hidden grade items and deleted activity modules automatically.

What it does not do:

- Does not modify grades.
- Does not alter enrolments except when explicitly unsuspending via API and capability-checked.
- Does not write directly to DB tables; uses enrol plugin API for updates.
- Does not delete or hide assignments; only controls submission access via cutoff dates.

## 2. Installation

### Method 1: ZIP Upload

1. Download this repository as a ZIP file (or clone and create a ZIP).
2. **Important:** Ensure the ZIP structure is correct:
   ```
   usercoursecontrol/
   ├── classes/
   ├── db/
   ├── lang/
   ├── version.php
   └── README.md
   ```
3. In Moodle, go to: Site administration → Plugins → Install plugins.
4. Upload the ZIP and follow the on-screen installer.
5. Complete the upgrade.
6. Purge caches: Site administration → Development → Purge all caches.

### Method 2: Manual Installation (Git Clone)

1. Navigate to your Moodle installation directory:
   ```bash
   cd [moodleroot]/local/
   ```
2. Clone this repository:
   ```bash
   git clone https://github.com/tvricon/UserCourseControl-Plugin.git usercoursecontrol
   ```
3. Run the Moodle CLI upgrade:
   ```bash
   /usr/bin/php admin/cli/upgrade.php --non-interactive --allow-unstable
   ```
4. Purge caches:
   ```bash
   /usr/bin/php admin/cli/purge_caches.php
   ```

### Method 3: Direct File Copy

1. Download/clone this repository to your local machine.
2. Copy all files to `[moodleroot]/local/usercoursecontrol/` on your Moodle server.
3. Visit: `https://yourmoodle.example/admin/index.php` to trigger the upgrade.
4. Purge caches: Site administration → Development → Purge all caches.
   ```bash
   /usr/bin/php admin/cli/purge_caches.php
   ```

### Updating the plugin (important)

When updating plugin files on an existing installation:

1. **Bump the version number** in `version.php`:

   - Change `$plugin->version` to a higher value (e.g., `2026010800` for January 8, 2026)
   - Update `$plugin->release` accordingly (e.g., `1.1.0` → `1.2.0`)

2. **Upload the updated files**:

   - `classes/external.php` (if modified)
   - `db/services.php` (if modified)
   - `version.php` (always update when adding/changing functions)

3. **Trigger Moodle upgrade**:

   - Visit: `https://yourmoodle.example/admin/index.php`
   - Click "Upgrade Moodle database now"

4. **Clear PHP opcode cache** (if available):

   - Via cPanel: PHP OpCode Cache → Flush/Reset
   - Or restart web server (Apache/Nginx)

5. **Purge all caches**:
   - Site administration → Development → Purge all caches

**Important**: Simply replacing files without bumping the version will not work due to PHP opcode caching. Moodle needs to detect a version change to properly reload the new web service definitions.

## 3. Web Service Setup

1. Enable Web services: Site administration → Advanced features → Enable web services.
2. Enable REST protocol: Site administration → Plugins → Web services → Manage protocols → Enable REST.
3. Create an external service:
   - Site administration → Plugins → Web services → External services → Add new service.
   - Name: User Course Control Service
   - Enabled: Yes
4. Add functions to the service:
   - `local_usercoursecontrol_get_grade_status`
   - `local_usercoursecontrol_list_suspended_courses`
   - `local_usercoursecontrol_unsuspend_user`
   - `local_usercoursecontrol_get_user_courses_filtered`
   - `local_usercoursecontrol_get_turnitin_assignments`
   - `local_usercoursecontrol_toggle_turnitin_allowlate`
   - `local_usercoursecontrol_bulk_toggle_turnitin_allowlate`
   - `local_usercoursecontrol_get_assignments`
   - `local_usercoursecontrol_set_assignment_cutoff`
   - `local_usercoursecontrol_bulk_set_assignment_cutoff`
5. Create a token for a user who has the required capabilities:
   - Site administration → Plugins → Web services → Manage tokens → Add.

Required capabilities (assign via appropriate role to the token-holding user):

- For grade status (reading):
  - If querying other users: `moodle/grade:viewall` in the course context.
  - If querying own grades: `moodle/grade:view` in the course context.
- For listing suspended courses: `moodle/user:viewdetails` (suggested at system level).
- For unsuspending users: `enrol/<plugin>:manage` (e.g. `enrol/manual:manage`) in the target course.
- For filtered course list: `moodle/user:viewdetails` (suggested at system level).
- For Turnitin functions:
  - Getting assignments: `moodle/course:view` at system level.
  - Toggling allowlate: `moodle/course:manageactivities` in the course context.
- For standard assignment functions:
  - Getting assignments: `moodle/course:view` at system level.
  - Setting cutoff dates: `moodle/course:manageactivities` in the course context.

## 4. API Usage Examples

Base endpoint (REST):

```
https://your.moodle.example/webservice/rest/server.php
```

Required params for all calls:

- `wstoken` (service token)
- `moodlewsrestformat=json`
- `wsfunction` (one of the functions below)

### 4.1 Get grade modification indicator for individual grade items

Function: `local_usercoursecontrol_get_grade_status`

Params:

- `username`: target user's username (required)
- `courseid`: course ID (required)
- `gradeitemid`: grade item ID (optional, default: 0)
  - If `0` or omitted: Returns ALL grade items in the course (excludes course total)
  - If specified: Returns only that specific grade item

Example request (all grade items):

```
GET /webservice/rest/server.php?wstoken=XXXX&moodlewsrestformat=json&wsfunction=local_usercoursecontrol_get_grade_status&username=jdoe&courseid=5
```

Example request (specific grade item):

```
GET /webservice/rest/server.php?wstoken=XXXX&moodlewsrestformat=json&wsfunction=local_usercoursecontrol_get_grade_status&username=jdoe&courseid=5&gradeitemid=123
```

Example response:

```json
[
  {
    "gradeitemid": 123,
    "itemname": "Assignment 1",
    "itemtype": "mod",
    "itemmodule": "assign",
    "grademax": 100.0,
    "current_final_grade": 85.0,
    "was_modified": true,
    "last_modified": 1700000000,
    "modifier_userid": 12,
    "previous_final_grade": 80.0
  },
  {
    "gradeitemid": 124,
    "itemname": "Quiz 1",
    "itemtype": "mod",
    "itemmodule": "quiz",
    "grademax": 50.0,
    "current_final_grade": 45.0,
    "was_modified": false,
    "last_modified": 1699900000,
    "modifier_userid": null,
    "previous_final_grade": null
  }
]
```

Notes:

- Returns an **array** of grade items, even when requesting a specific item.
- Reads from `grade_items` and `grade_grades` tables.
- Excludes course total grade item (itemtype='course').
- Filters out deleted/hidden grade items automatically.
- Filters out grade items from deleted activity modules.
- Detects modifications by comparing current grade with grade_grades_history records.
- If history record differs from current grade (by > 0.001), `was_modified` is `true`.
- If history is disabled or no differing history exists, `was_modified` will be `false`.

### 4.2 List suspended course enrolments

Function: `local_usercoursecontrol_list_suspended_courses`

Params:

- `username`: target user’s username

Example request:

```
GET /webservice/rest/server.php?wstoken=XXXX&moodlewsrestformat=json&wsfunction=local_usercoursecontrol_list_suspended_courses&username=jdoe
```

Example response:

```json
[
  {
    "courseid": 2,
    "fullname": "Biology 101",
    "shortname": "BIO101",
    "suspensiontimestamp": 1699905000
  },
  {
    "courseid": 7,
    "fullname": "Chemistry 201",
    "shortname": "CHEM201",
    "suspensiontimestamp": 1699701000
  }
]
```

### 4.3 Unsuspend user in a course

Function: `local_usercoursecontrol_unsuspend_user`

Params:

- `username`: target user’s username
- `courseid`: course ID

Example request:

```
POST /webservice/rest/server.php
  wstoken=XXXX
  moodlewsrestformat=json
  wsfunction=local_usercoursecontrol_unsuspend_user
  username=jdoe
  courseid=5
```

Example response (success):

```json
{
  "success": true,
  "previous_status": 1,
  "new_status": 0
}
```

Example response (not suspended):

```json
{
  "success": false,
  "previous_status": 0,
  "new_status": 0
}
```

Error case (not enrolled):

```json
{
  "exception": "invalid_parameter_exception",
  "errorcode": "invalidparameter",
  "message": "User is not enrolled in the specified course."
}
```

### 4.4 Get user's enrolled courses with filtering

Function: `local_usercoursecontrol_get_user_courses_filtered`

Params:

- `username`: target user's username (required)
- `coursefullnamefilter`: comma or newline separated partial course fullname matches (optional)
- `courseshortnamefilter`: comma or newline separated exact course shortname matches (optional)

Example request (all courses):

```
GET /webservice/rest/server.php?wstoken=XXXX&moodlewsrestformat=json&wsfunction=local_usercoursecontrol_get_user_courses_filtered&username=jdoe
```

Example request (filter by fullname):

```
GET /webservice/rest/server.php?wstoken=XXXX&moodlewsrestformat=json&wsfunction=local_usercoursecontrol_get_user_courses_filtered&username=jdoe&coursefullnamefilter=Mathematics,Introduction
```

Example request (filter by shortname):

```
GET /webservice/rest/server.php?wstoken=XXXX&moodlewsrestformat=json&wsfunction=local_usercoursecontrol_get_user_courses_filtered&username=jdoe&courseshortnamefilter=MATH101,CS202
```

Example request (combine both filters):

```
GET /webservice/rest/server.php?wstoken=XXXX&moodlewsrestformat=json&wsfunction=local_usercoursecontrol_get_user_courses_filtered&username=jdoe&coursefullnamefilter=Advanced&courseshortnamefilter=CS301
```

Example response:

```json
[
  {
    "courseid": 5,
    "fullname": "Introduction to Mathematics",
    "shortname": "MATH101",
    "category": 2,
    "startdate": 1693526400,
    "enddate": 1704067200,
    "enrolmentstatus": 0,
    "enrolmenttimecreated": 1693526500,
    "enrolmenttimemodified": 1693526500,
    "enrolmethod": "manual"
  },
  {
    "courseid": 12,
    "fullname": "Advanced Mathematics",
    "shortname": "MATH201",
    "category": 2,
    "startdate": 1704067200,
    "enddate": 1715616000,
    "enrolmentstatus": 1,
    "enrolmenttimecreated": 1704067300,
    "enrolmenttimemodified": 1710000000,
    "enrolmethod": "self"
  }
]
```

Notes:

- **Database-level filtering**: SQL WHERE clauses are applied before fetching, reducing network traffic and improving performance.
- **Fullname filtering**: Uses SQL LIKE for partial matches (case-insensitive). Multiple values are OR'd together.
- **Shortname filtering**: Uses exact matches. Multiple values are OR'd together.
- **Combined filters**: When both filters are provided, they are AND'd together (course must match fullname filter AND shortname filter).
- **Multiple values**: Separate with commas or newlines: `MATH101,CS202` or `MATH101\nCS202`
- **Enrollment status**: `0` = active, `1` = suspended.
- **Returns only enrolled courses**: User must have an active enrollment record (includes both active and suspended enrollments).
- **Sorted by fullname**: Results are ordered alphabetically by course fullname.

### 4.5 Get Turnitin assignments by course and due date

Function: `local_usercoursecontrol_get_turnitin_assignments`

Params:

- `coursenames`: array of course names for exact shortname or partial fullname match (required)
- `duedatestart`: start of due date range in Unix timestamp (required)
- `duedateend`: end of due date range in Unix timestamp (required)

Example request:

```
GET /webservice/rest/server.php?wstoken=XXXX&moodlewsrestformat=json&wsfunction=local_usercoursecontrol_get_turnitin_assignments&coursenames[0]=MATH101&coursenames[1]=Biology&duedatestart=1704067200&duedateend=1704153600
```

Example response:

```json
[
  {
    "partId": 45,
    "assignmentId": 12,
    "assignmentName": "Research Paper",
    "partName": "Part 1",
    "tiiAssignId": 987654,
    "courseId": 5,
    "courseShortname": "MATH101",
    "courseFullname": "Introduction to Mathematics",
    "dueDate": 1704067200,
    "dueDateFormatted": "01-01-2024",
    "allowLate": true,
    "reportGenSpeed": 1
  },
  {
    "partId": 46,
    "assignmentId": 13,
    "assignmentName": "Lab Report",
    "partName": "Submission",
    "tiiAssignId": 987655,
    "courseId": 8,
    "courseShortname": "BIO101",
    "courseFullname": "Introduction to Biology",
    "dueDate": 1704153600,
    "dueDateFormatted": "02-01-2024",
    "allowLate": false,
    "reportGenSpeed": 2
  }
]
```

Notes:

- Searches by **exact shortname match OR partial fullname match** (case-insensitive).
- Returns all Turnitin assignment parts matching the course and due date criteria.
- Field names use **camelCase** for API consistency.
- `allowLate`: `true` means students can submit after due date, `false` means they cannot.
- Ordered by due date, then course fullname, then assignment name.

### 4.6 Toggle late submissions for a Turnitin assignment part

Function: `local_usercoursecontrol_toggle_turnitin_allowlate`

Params:

- `partid`: Turnitin part ID (required)
- `allowlate`: boolean - true to allow late submissions, false to block (required)

Example request:

```
POST /webservice/rest/server.php
  wstoken=XXXX
  moodlewsrestformat=json
  wsfunction=local_usercoursecontrol_toggle_turnitin_allowlate
  partid=45
  allowlate=0
```

Example response:

```json
{
  "success": true,
  "partid": 45,
  "allowlate": false
}
```

Notes:

- Requires `moodle/course:manageactivities` capability in the course context.
- Updates the `allowlate` field in `mdl_turnitintooltwo_parts` table.
- Use `allowlate=0` or `false` to prevent late submissions.
- Use `allowlate=1` or `true` to allow late submissions.

### 4.7 Bulk toggle late submissions for multiple Turnitin parts

Function: `local_usercoursecontrol_bulk_toggle_turnitin_allowlate`

Params:

- `partids`: array of Turnitin part IDs (required)
- `allowlate`: boolean - true to allow late submissions, false to block (required)

Example request:

```
POST /webservice/rest/server.php
  wstoken=XXXX
  moodlewsrestformat=json
  wsfunction=local_usercoursecontrol_bulk_toggle_turnitin_allowlate
  partids[0]=45
  partids[1]=46
  partids[2]=47
  allowlate=0
```

Example response:

```json
{
  "success": 3,
  "failed": 0,
  "total": 3
}
```

Notes:

- Processes multiple parts in one request.
- Returns counts: `success` (updated successfully), `failed` (capability issues or not found), `total` (processed).
- Continues processing even if some parts fail.
- Each part requires `moodle/course:manageactivities` in its respective course.

### 4.8 Get standard Moodle assignments by course and due date

Function: `local_usercoursecontrol_get_assignments`

Params:

- `coursenames`: array of course names for exact shortname or partial fullname match (required)
- `duedatestart`: start of due date range in Unix timestamp (required)
- `duedateend`: end of due date range in Unix timestamp (required)

Example request:

```
GET /webservice/rest/server.php?wstoken=XXXX&moodlewsrestformat=json&wsfunction=local_usercoursecontrol_get_assignments&coursenames[0]=CS202&coursenames[1]=Advanced&duedatestart=1704067200&duedateend=1704153600
```

Example response:

```json
[
  {
    "assignmentId": 34,
    "assignmentName": "Programming Assignment 3",
    "courseId": 12,
    "courseShortname": "CS202",
    "courseFullname": "Advanced Programming",
    "cmId": 156,
    "visible": true,
    "dueDate": 1704067200,
    "dueDateFormatted": "01-01-2024",
    "cutoffDate": 1704153600,
    "cutoffDateFormatted": "02-01-2024",
    "allowSubmissionsFromDate": 1703462400,
    "allowSubmissionsFromDateFormatted": "25-12-2023",
    "gradeMax": 100.0,
    "submissionsOpen": false
  }
]
```

Notes:

- Searches by **exact shortname match OR partial fullname match** (case-insensitive).
- Returns standard Moodle assignments (not Turnitin).
- `submissionsOpen`: Calculated field showing if students can currently submit based on cutoff date and allowSubmissionsFromDate.
- `cutoffDate`: If set and passed, students cannot submit (but can still view the assignment).
- `visible`: Assignment visibility status.
- `cmId`: Course module ID for the assignment.
- Ordered by due date, then course fullname, then assignment name.

### 4.9 Set cutoff date for a standard Moodle assignment

Function: `local_usercoursecontrol_set_assignment_cutoff`

Params:

- `assignmentid`: Assignment ID (required)
- `cutoffdate`: Unix timestamp for cutoff date, or 0 to remove cutoff (required)

Example request (set cutoff):

```
POST /webservice/rest/server.php
  wstoken=XXXX
  moodlewsrestformat=json
  wsfunction=local_usercoursecontrol_set_assignment_cutoff
  assignmentid=34
  cutoffdate=1704067200
```

Example request (remove cutoff):

```
POST /webservice/rest/server.php
  wstoken=XXXX
  moodlewsrestformat=json
  wsfunction=local_usercoursecontrol_set_assignment_cutoff
  assignmentid=34
  cutoffdate=0
```

Example response:

```json
{
  "success": true,
  "assignmentid": 34,
  "cutoffdate": 1704067200
}
```

Notes:

- **After cutoff date**: Students can VIEW the assignment and see the due date, but CANNOT submit.
- **Teachers**: Always have full access regardless of cutoff date.
- Students are not confused - they can see why they can't submit (deadline passed).
- Set `cutoffdate=0` to remove the cutoff and allow submissions again.
- Requires `moodle/course:manageactivities` capability in the course context.

### 4.10 Bulk set cutoff date for multiple assignments

Function: `local_usercoursecontrol_bulk_set_assignment_cutoff`

Params:

- `assignmentids`: array of assignment IDs (required)
- `cutoffdate`: Unix timestamp for cutoff date, or 0 to remove cutoff (required)

Example request:

```
POST /webservice/rest/server.php
  wstoken=XXXX
  moodlewsrestformat=json
  wsfunction=local_usercoursecontrol_bulk_set_assignment_cutoff
  assignmentids[0]=34
  assignmentids[1]=35
  assignmentids[2]=36
  cutoffdate=1704067200
```

Example response:

```json
{
  "success": 3,
  "failed": 0,
  "total": 3
}
```

Notes:

- Processes multiple assignments in one request.
- Returns counts: `success` (updated successfully), `failed` (capability issues or not found), `total` (processed).
- Continues processing even if some assignments fail.
- Each assignment requires `moodle/course:manageactivities` in its respective course.
- Useful for bulk-disabling submissions across many assignments at once.

## 5. Security Notes

- Capability-checked endpoints:
  - Grades: `moodle/grade:viewall` (others) or `moodle/grade:view` (self) in course context.
  - Suspended courses list: `moodle/user:viewdetails` at system context.
  - Unsuspend: `enrol/<plugin>:manage` in the course.
  - Filtered course list: `moodle/user:viewdetails` at system context.
  - Turnitin get: `moodle/course:view` at system context.
  - Turnitin toggle: `moodle/course:manageactivities` in course context (per-assignment).
  - Assignment get: `moodle/course:view` at system context.
  - Assignment cutoff: `moodle/course:manageactivities` in course context (per-assignment).
- Recommended: Create a dedicated service role with only the minimum required capabilities and assign via token.
- All parameters are validated via `external_api` parameter definitions.
- Bulk operations check capabilities per-item and skip items where user lacks permission.

## 6. Compatibility Notes

- Supported Moodle versions: 3.9 (LTS) and later.
- No DB schema changes or observers; upgrades should be low risk.
- Uses only supported tables and stable APIs (enrol, grades, assign, turnitintooltwo).
- Turnitin functions require Turnitin Direct V2 plugin to be installed.

## 7. Non-Functional

- Efficient queries using indexed joins; minimal data returned.
- No cron, no UI, no admin settings.
- Bulk operations process items individually with error handling.
- Date filtering uses database-level WHERE clauses for performance.

## 8. Development

- Component: `local_usercoursecontrol`
- Files:
  - `version.php`
  - `db/services.php`
  - `classes/external.php`
  - `lang/en/local_usercoursecontrol.php`
