<?php

declare(strict_types=1);

namespace Bga\Games\makaucloudnein\States;

use Bga\Games\makaucloudnein\Game;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\UserException;
use Bga\GameFramework\Actions\Types\IntArrayParam;
use Bga\GameFramework\NotificationMessage;


class GameOn extends GameState
{
    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: 31,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
            descriptionMyTurn: clienttranslate('${you} may play cards or draw')
        );
    }

    function onEnteringState() {}

    #[PossibleAction]
    public function actPass(int $currentPlayerId)
    {
        if ($this->game->getPlayerNoById($currentPlayerId) != $this->bga->globals->get('active_player_no'))
            return null;

        return NextPlayer::class;
    }

    #[PossibleAction]
    public function actDraw(int $currentPlayerId)
    {
        if ($this->game->getPlayerNoById($currentPlayerId) != $this->bga->globals->get('active_player_no'))
            return null;


        if ($this->bga->globals->get('drew')) return; // can only draw once
        $this->bga->globals->set('drew', 1);

        $nextplayer = false;
        $draw_count = $this->bga->globals->get('draw_count');
        if ($draw_count == 0) $draw_count = 1;
        else $nextplayer = true;

        $cards = $this->game->cards->pickItems($draw_count, 'deck', ['hand', $currentPlayerId])->values();

        $this->bga->globals->set('draw_count', 0);

        $this->game->notify->all(
            'DrawCards',
            clienttranslate('${player_name} takes card(s) from the deck'),
            [
                'player_name' => $this->game->getPlayerNameById($currentPlayerId),
                '_private' => [
                    $currentPlayerId => [
                        'cards' => $cards
                    ]
                ]
            ]
        );

        if ($nextplayer) return NextPlayer::class; // when forced to draw, I am done
        return null;
    }

    #[PossibleAction]
    public function actPlay(#[IntArrayParam()] array $cardIds, int $currentPlayerId)
    {
        if ($this->game->getPlayerNoById($currentPlayerId) != $this->bga->globals->get('active_player_no'))
            return null;

        $discards = $this->game->cards->getItemsInLocation('discard');
        $top = $this->game->cards->getItemOnTop('discard');
        $playedCards = [];

        $cards = $this->game->cards->getItemsByIds($cardIds)->values();
        $playableCards = $this->game->getPlayableCards($top, $cards);

        if (count($playableCards) < count($cards)) {
            $this->game->notify->player(
                $currentPlayerId,
                'InvalidPlay',
                'You cannot play ' . $this->game->getCardName($cards[count($playableCards)]),
                []
            );
            return null;
        }

        $skip_count = $this->bga->globals->get('skip_count');
        $draw_count = $this->bga->globals->get('draw_count');
        $suit_demand = $this->bga->globals->get('suit_demand');
        $rank_demand = $this->bga->globals->get('rank_demand');
        $last_jack = $this->bga->globals->get('last_jack');

        for ($i = 0; $i < count($cards); $i++) {
            $card = $cards[$i];
            if ($card->rank == 2 || $card->rank == 3) {
                $draw_count += $card->rank;
            }
            if ($card->rank == 4) {
                $skip_count++;
            }
        }

        $this->bga->globals->set('skip_count', $skip_count);
        $this->bga->globals->set('draw_count', $draw_count);
        $this->bga->globals->set('suit_demand', $suit_demand);
        $this->bga->globals->set('rank_demand', $rank_demand);
        $this->bga->globals->set('last_jack', $last_jack);

        // valid play, move the cards to the discard pile
        $this->game->cards->moveItems($cards, 'discard');
        $this->game->notify->all(
            'PlayCards',
            '',
            [
                'cards' => $cards,
                'skip_count' => $skip_count,
                'draw_count' => $draw_count,
                'suit_demand' => $suit_demand,
                'rank_demand' => $rank_demand,
                'last_jack' => $last_jack,
            ]
        );

        return NextPlayer::class;
    }

    public function zombie(int $playerId)
    {
        // We must implement this so BGA can auto play in the case a player becomes a zombie, but for this tutorial we won't handle this case
        throw new UserException('Not implemented: zombie for player ${player_id}');
    }
}
