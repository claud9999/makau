<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein\States;

use Bga\Games\makaucloudnein\States\GameOn;

use Bga\Games\makaucloudnein\Game;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;

class PrevPlayer extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: 36,
            type: StateType::GAME,
            descriptionMyTurn: clienttranslate('prev player')
        );
    }

    public function onEnteringState()
    {
        $this->game->activePrevPlayer();

        return GameOn::class;
    }
}
