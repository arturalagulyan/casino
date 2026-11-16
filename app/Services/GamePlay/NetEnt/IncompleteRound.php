<?php

namespace App\Services\GamePlay\NetEnt;

use RuntimeException;

/**
 * The game server rejected a step of a round — answered with a bare
 * `clientaction=<action>` and no `nextaction`. Typically a pick bonus whose
 * request needs the player's choice (`freespins.option`, keys, a team, …),
 * which a recording session cannot make. The round cannot be finished, and
 * the session is left inside the bonus, so it must be dropped too.
 */
class IncompleteRound extends RuntimeException {}
