# Changelog

All Notable changes to `:package_name` will be documented in this file.

Updates should follow the [Keep a CHANGELOG](http://keepachangelog.com/) principles.

## NEXT - YYYY-MM-DD

### Added
- Nothing

### Deprecated
- Nothing

### Fixed
- `save()` no longer leaves a transaction level open when it fails. It now rolls back to the
  level it was called at when the save throws and when it answers `false` (a `saving` listener
  cancelling, or a nested child update refusing). Before, the open level turned the caller's
  `commit()` into a savepoint release and made the caller's `rollBack()` undo the wrong level.
- A failing rollback no longer replaces the exception that caused it: it is reported (when the app
  has a `report()` helper) and the original exception is rethrown. When the server has already
  ended the transaction, the level counter is realigned to zero without issuing SQL.
- The transaction now runs on the model's own connection instead of the default one.
- A NEW nested child (HasMany/MorphMany) whose `save()` answers `false` now makes the parent's
  `save()` answer `false` and roll back. It used to be skipped silently: `create()` returns the model
  even when its save is refused, so the parent was committed without the child and reported success.

### Removed
- Nothing

### Security
- Nothing
