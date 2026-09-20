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


class PickRank extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: 33,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
            descriptionMyTurn: clienttranslate('pick rank'),
        );
    }

    function onEnteringState()
    {
        $active_player_no = $this->bga->globals->get('active_player_no');

        $this->game->bga->notify->all('PickRank', '', [
            'active_player_no' => $active_player_no,
        ]);
    }

    #[PossibleAction]
    public function actPick(int $rank, int $currentPlayerId)
    {
        $globals = $this->bga->globals;

        $globals->set('rank_demand', $rank);
        $globals->set('last_jack', $currentPlayerId);

        $this->game->bga->notify->all('RankDemand', '', [
            'last_jack' => $currentPlayerId,
            'rank_demand' => $rank,
        ]);

        return NextPlayer::class;
    }


    public function zombie(int $playerId)
    {
        // We must implement this so BGA can auto play in the case a player becomes a zombie, but for this tutorial we won't handle this case
        throw new UserException('Not implemented: zombie for player ${player_id}');
    }
}
