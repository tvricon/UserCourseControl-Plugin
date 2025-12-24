# local_usercoursecontrol

Lightweight Moodle local plugin exposing custom Web Service APIs for:

- Grade modification history for all course grade items (assignments, quizzes, etc.)
- Listing suspended course enrolments for a user
- Reinstate (unsuspend) a user in a course via enrol plugin API

No core changes. Uses only supported Moodle APIs and tables.

## 1. Plugin Overview

- Purpose: Provide REST/JSON Web Service endpoints for external systems to query individual grade items (assignments, quizzes, etc.) with modification history, list courses where the user is suspended, and unsuspend a user without re-enrolling.
- Non-invasive: No schema changes, observers, events, cron, UI pages, or settings.
- Data sources: grade_grades, grade_grades_history, grade_items, enrol, user_enrolments, course.
- Security: Strict parameter validation, capability checks, and exceptions for invalid states.
- Filters out deleted/hidden grade items and deleted activity modules automatically.

What it does not do:

- Does not modify grades.
- Does not alter enrolments except when explicitly unsuspending via API and capability-checked.
- Does not write directly to DB tables; uses enrol plugin API for updates.

## 2. Installation

### ZIP upload

1. Create a ZIP of the `local/usercoursecontrol` directory so that the ZIP contains the `usercoursecontrol` folder inside `local/`.
2. In Moodle, go to: Site administration → Plugins → Install plugins.
3. Upload the ZIP and follow the on-screen installer.
4. Complete the upgrade.
5. Purge caches: Site administration → Development → Purge all caches.

### CLI installation (optional)

1. Copy the `local/usercoursecontrol` directory into `[moodleroot]/local/`.
2. Run the Moodle CLI upgrade:
   ```bash
   /usr/bin/php admin/cli/upgrade.php --non-interactive --allow-unstable
   ```
3. Purge caches:
   ```bash
   /usr/bin/php admin/cli/purge_caches.php
   ```

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
5. Create a token for a user who has the required capabilities:
   - Site administration → Plugins → Web services → Manage tokens → Add.

Required capabilities (assign via appropriate role to the token-holding user):

- For grade status (reading):
  - If querying other users: `moodle/grade:viewall` in the course context.
  - If querying own grades: `moodle/grade:view` in the course context.
- For listing suspended courses: `moodle/user:viewdetails` (suggested at system level).
- For unsuspending users: `enrol/<plugin>:manage` (e.g. `enrol/manual:manage`) in the target course.

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

## 5. Security Notes

- Capability-checked endpoints:
  - Grades: `moodle/grade:viewall` (others) or `moodle/grade:view` (self) in course context.
  - Suspended courses list: `moodle/user:viewdetails` at system context.
  - Unsuspend: `enrol/<plugin>:manage` in the course.
- Recommended: Create a dedicated service role with only the minimum required capabilities and assign via token.
- All parameters are validated via `external_api` parameter definitions.

## 6. Compatibility Notes

- Supported Moodle versions: 3.9 (LTS) and later.
- No DB schema changes or observers; upgrades should be low risk.
- Uses only supported tables and stable APIs (enrol, grades).

## 7. Non-Functional

- Efficient queries using indexed joins; minimal data returned.
- No cron, no UI, no admin settings.

## 8. Development

- Component: `local_usercoursecontrol`
- Files:
  - `version.php`
  - `db/services.php`
  - `classes/external.php`
  - `lang/en/local_usercoursecontrol.php`
