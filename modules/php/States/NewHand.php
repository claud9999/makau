<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein\States;

use Bga\Games\makaucloudnein\Game;
use Bga\Games\makaucloudnein\States\NextPlayer;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\UserException;
use Bga\GameFramework\Actions\Types\IntArrayParam;
use Bga\GameFramework\NotificationMessage;


class NewHand extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: 2,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
            descriptionMyTurn: clienttranslate('dealing hands'),
        );
    }

    function onEnteringState()
    {
        $this->gamestate->setAllPlayersMultiactive();

        $this->game->reset();

        return NextPlayer::class;
    }

    public function zombie(int $playerId)
    {
        // We must implement this so BGA can auto play in the case a player becomes a zombie, but for this tutorial we won't handle this case
        throw new UserException('Not implemented: zombie for player ${player_id}');
    }
}