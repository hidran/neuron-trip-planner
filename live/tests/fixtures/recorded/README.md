# Recorded responses

Empty on purpose. Run `php bin/record-fixtures.php` once, online, and this
directory fills with the real responses of every API the tools use. Commit
them: `tests/RecordedApisTest.php` replays them offline and fails the day a
service changes the shape of its answer. Until then that test is skipped.
