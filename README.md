# MantisHub Moderate Plugin for MantisBT

Victor Boctor @ [MantisHub](https://www.mantishub.com)

## Overview

The Moderate plugin enables moderation of new issues and notes before they become visible to
other users and trigger email notifications. This is useful for:

- Preventing spam submissions
- Moderating user-generated content in community projects

## Features

- **Issue Moderation**: Queue new issues for approval before they are created
- **Note Moderation**: Queue new notes for approval before they are added to issues
- **Auto Approval**: auto-approve when appropriate.
- **Simple Queue Management**: Easy-to-use interface for reviewing and approving/rejecting items
- **Email Suppression**: Email notifications are only sent after items are approved
- **Auto Truncation**: Auto-truncation of entries moderated more than 30 days ago.

## Installation

1. Copy the `Moderate` directory to your MantisBT `plugins` directory
2. Log in to MantisBT as an administrator
3. Navigate to **Manage** > **Manage Plugins**
4. Find **Moderate** in the available plugins list and click **Install**
5. After installation, click **Configure** to set up the plugin

## Configuration

The plugin provides the following configuration options accessible via **Manage** > **Manage Plugins** >
**Moderate** > **Configuration**:

### Moderate Bypass Threshold

Users at or above this access level can create issues and notes without moderation. Users below this
threshold will have their submissions queued for approval.

**Default**: DEVELOPER

### Moderate Threshold

Users at or above this access level can manage the moderation queue and approve or reject items.

**Default**: MANAGER

## Auto-Approval / Bypass Moderation Rules

The plugin automatically bypasses moderation in the following scenarios, meaning the issue or note is
created immediately without requiring approval:

### 1. User Access Level Bypass

**Rule**: Users with an access level **at or above** the Moderate Bypass Threshold bypass moderation for
all their submissions.

**Configuration**: Set via the "Moderate Bypass Threshold" setting (default: DEVELOPER)

**Example Scenarios**:

- If bypass threshold is DEVELOPER (55), then:
  - ✅ DEVELOPER, MANAGER, and ADMINISTRATOR users bypass moderation
  - ❌ REPORTER and UPDATER users require moderation

- If you want only MANAGER and above to bypass moderation:
  - Set bypass threshold to MANAGER (70)
  - DEVELOPER, UPDATER, and REPORTER users will require moderation

**Use Case**: Trusted contributors and staff members should be able to report issues and add notes
without delays.

### 2. Own Issue Notes Bypass

**Rule**: Users adding notes to **their own issues** (issues they originally reported) automatically
bypass moderation.

**Rationale**: Issue reporters should be able to freely update and add information to their own issues
without waiting for moderation approval. This is essential for effective issue tracking.

**Example Scenarios**:

- User A (REPORTER level) creates an issue → Goes to moderation queue
- After approval, User A adds a note to their issue → **Bypasses moderation** (created immediately)
- User B (REPORTER level) adds a note to User A's issue → Goes to moderation queue

**Exception**: This bypass applies regardless of the user's access level. Even users below the bypass
threshold can add notes to their own issues without moderation.

## Moderation Decision Logic

Here's how the system determines whether an issue or note requires moderation:

### For New Issues

```
if (user access level >= bypass threshold) {
    ✅ Create immediately (bypass moderation)
} else {
    ❌ Send to moderation queue
}
```

### For New Notes

```
if (user access level >= bypass threshold) {
    ✅ Create immediately (bypass moderation)
} else if (user is the original issue reporter) {
    ✅ Create immediately (own issue bypass)
} else {
    ❌ Send to moderation queue
}
```

## Project-Based Access Control

Moderation access is enforced on a per-project basis:

- **Moderators** can only see and moderate items from projects where they have the "Moderate
  Threshold" access level. This should be higher or equal to access to private issues and notes.
- **All Projects View**: When "All Projects" is selected, moderators see items from all projects they
  have moderation access to
- **Specific Project View**: When a specific project is selected, only items from that project are
  shown (if the moderator has access)
- **Access Denied**: Users without moderation access to any project cannot access the moderation
  queue

## Usage

### For Moderators

1. Navigate to **Manage** > **Moderation Queue**
2. The queue has two views accessible via tabs:
   - **Pending**: Shows items awaiting moderation
   - **History**: Shows all items including already moderated ones.

#### Moderation Actions

When reviewing items in the moderation queue, moderators have several actions available:

- **Approve**: Creates the issue or note. Email notifications are sent at this point.
  This action is used for legitimate submissions.

- **Reject**: Marks the item as rejected without creating the issue or note. Use this for
  items that don't meet quality standards, are duplicates, or are off-topic but are not spam.

- **Spam**: Marks the item and **all pending items from the same reporter** as spam and
  **disables the reporter's account**. This is a powerful action intended for clear spam submissions.
  The user will no longer be able to sign in or submit new issues. Only users with "Manage Users"
  permission can use this action.

- **Delete**: Permanently removes the item from the moderation queue. This action cannot be undone and
  should be used sparingly.

Note that moderated queue entries are deleted 30 days after moderation.

### For Users

Users whose submissions require moderation will submit issues and notes normally through the standard
MantisBT interface. After submission:

- The user will see a confirmation message
- The item will be placed in the moderation queue
- The item will not appear in issue lists or searches
- No email notifications will be sent
- Once approved, the item will appear normally and notifications will be sent

## How It Works

### Technical Details

1. **Event Hooks**: The plugin hooks into `EVENT_REPORT_BUG` and `EVENT_BUGNOTE_ADD`
2. **Interception**: When an item is created, the plugin checks if moderation is needed
3. **Queue Storage**: If moderation is required, the item data is serialized and stored in the
   queue
4. **Original Deletion**: The original issue/note is deleted to prevent it from appearing
5. **Approval Process**: When approved, the item is recreated from the stored data
6. **Email Trigger**: Email notifications are sent only after approval

## REST API

The plugin provides REST API endpoints for programmatic access to the moderation queue:

### Endpoints

#### Get Pending Queue

```bash
GET /api/rest/moderate/queue?project_id={id}
```

Returns list of pending moderation items. Optional `project_id` parameter filters by project.

**Response:**

```json
{
  "items": [
    {
      "id": 123,
      "type": "issue",
      "project_id": 1,
      "reporter_id": 5,
      "bug_id": null,
      "date_submitted": 1234567890,
      "status": 0,
      "status_name": "Pending",
      "moderator_id": null,
      "date_moderated": null
    }
  ]
}
```

#### Get Moderation History

```bash
GET /api/rest/moderate/history?project_id={id}&limit={count}
```

Returns moderation history (approved/rejected items). Optional parameters:

- `project_id`: Filter by project
- `limit`: Maximum items to return (default: 50)

**Response:** Same format as queue endpoint, with `status`, `moderator_id`, and `date_moderated`
populated.

#### Approve Item

```bash
POST /api/rest/moderate/approve/{queue_id}
```

Approves a pending moderation item and creates the issue/note.

**Response:**

```json
{
  "queue_id": 123,
  "status": "approved",
  "type": "issue",
  "result_id": 456,
  "bug_id": 456
}
```

#### Reject Item

```bash
POST /api/rest/moderate/reject/{queue_id}
```

Rejects a pending moderation item.

**Request Body (optional):**

```json
{
  "reason": "Spam or inappropriate content"
}
```

**Response:**

```json
{
  "queue_id": 123,
  "status": "rejected",
  "type": "issue",
  "reason": "Spam or inappropriate content"
}
```

#### Get Statistics

```bash
GET /api/rest/moderate/stats?project_id={id}
```

Returns moderation queue statistics. Optional `project_id` parameter filters by project.

**Response:**

```json
{
  "pending_count": 5
}
```

## Limitations

- When an issue or a note requires moderation, attaching files is not allowed.

## Future Enhancements

Potential improvements for future versions:

- Show a hint in the UI for moderators when there are items pending moderation.
- Supports submissions of issues or notes with files for moderation.
- Notification to moderators when there are entries pending moderation with direct link.

## License

This plugin is released under GPL v2 license.
