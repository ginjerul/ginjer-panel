# Working in this repository

## Git
- Commit and push straight to `main`. The owner doesn't want pull requests or feature branches; they asked not to have to merge each change.
- Every push runs `.github/workflows/tests.yml` (free: this repository is public). Run the tests before pushing; the README lists how.
- The Reef shard egg's `update_url` points at `main`.

## The project
- `plugins/ginjer-network/`: a Pelican Panel plugin (Laravel 13, Filament 5) for sharded Nodes networks: networks, splitting a server into shards, health, rolling updates, backups, and per-server Performance and Plugins pages.
- `eggs/egg-reef-shard.yaml`: the egg for one Reef shard.
- Tests are plain PHP scripts in `plugins/ginjer-network/tests/` (no PHPUnit): standalone ones take a `vendor/autoload.php`, panel ones run from a Pelican install with the plugin installed. Wings is always faked.
- Setting names the optimizer edits were checked against Paper's source for the version Reef is built on; check the source again before adding or renaming any.
- Related repositories: `ginjerul/reef` and `ginjerul/ginjer-nodes`.
