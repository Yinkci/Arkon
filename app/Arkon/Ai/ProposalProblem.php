<?php

namespace App\Arkon\Ai;

use RuntimeException;

/** One change in a reply that cannot be applied; collected into the proposal's issues. */
final class ProposalProblem extends RuntimeException {}
