# Measurement definitions

- `files_read`: distinct repository source/test/config files whose contents are exposed to the strategy before candidate freeze.
- `source_bytes`: exact bytes of those exposed repository file contents/ranges; do not count tool metadata.
- `map_output_bytes`: bytes of Map/Search/context output exposed to the consumer; zero for A.
- `candidate_count`: distinct candidate files presented as plausible edit/verification locations before grading.
- `commands`: discovery/read/Map commands issued from strategy start through candidate freeze.
- `correct_edit_site`: oracle-graded only after freeze.
- `correct_test`: oracle-graded only after freeze.
- `false_candidates`: presented candidate files outside the verified edit/verification set.
- `missing_observation_fact`: exact fact needed for safe interpretation that the strategy's observation channel could not establish; `none` is valid.