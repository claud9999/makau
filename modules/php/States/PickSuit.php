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


class PickSuit extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: 34,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
            descriptionMyTurn: clienttranslate('pick suit'),
        );
    }

    function onEnteringState(int $activePlayerId, array $args): void
    {
        $game = $this->game;
        $active_player_id = $this->bga->globals->get('active_player_id');

        $game->bga->notify->all('PickSuit', '', [
            'active_player_id' => $active_player_id,
            'player_name' => $game->getPlayerNameById($active_player_id),
        ]);
    }

    #[PossibleAction]
    public function actPick(int $suit, int $currentPlayerId)
    {
        $globals = $this->bga->globals;

        $globals->set('suit_demand', $suit);

        $this->game->bga->notify->all('RankDemand', '', [
            'suit_demand' => $suit,
        ]);

        return NextPlayer::class;
    }


    public function zombie(int $playerId)
    {
        // We must implement this so BGA can auto play in the case a player becomes a zombie, but for this tutorial we won't handle this case
        throw new UserException("Not implemented: zombie for player ${player_id}");
    }
}
