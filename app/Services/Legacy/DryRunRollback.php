<?php

namespace App\Services\Legacy;

/** Thrown to roll back the wrapping transaction on `import:legacy --dry-run`. */
class DryRunRollback extends \RuntimeException {}
