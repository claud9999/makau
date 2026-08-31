<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein\States;

use Bga\GameFramework\StateType;
use Bga\Games\makaucloudnein\Game;

const ST_END_GAME = 99;

class EndScore extends \Bga\GameFramework\States\GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 98,
            type: StateType::GAME,
        );
    }

    public function onEnteringState() {
        return ST_END_GAME;
    }
}