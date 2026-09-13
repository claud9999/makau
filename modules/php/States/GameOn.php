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

        // I have more skips to skip
        if ($this->bga->globals->get('skip_' . $currentPlayerId) > 0)
            $this->bga->globals->set('skip_' . $currentPlayerId, $this->bga->globals->get('skip_' . $currentPlayerId) - 1);

        // first skip
        if ($this->bga->globals->get('skip') > 0) {
            $this->bga->globals->set('skip_' . $currentPlayerId, $this->bga->globals->get('skip') - 1);
            $this->bga->globals->set('skip', 0);
        }

        $this->game->notify->all(
            'Pass',
            clienttranslate('${player_name} passes'),
            [
                'player_name' => $this->game->getPlayerNameById($currentPlayerId),
                'player_ids' => [$currentPlayerId],
            ]
        );

        return NextPlayer::class;
    }

    #[PossibleAction]
    public function actDraw(int $currentPlayerId)
    {
        if ($this->game->getPlayerNoById($currentPlayerId) != $this->bga->globals->get('active_player_no'))
            return null;

        if ($this->bga->globals->get('drew')) return; // can only draw once
        $nextplayer = false;
        $draw_count = $this->bga->globals->get('draw_' . $currentPlayerId);
        if ($draw_count == 0) $draw_count = 1;
        else $nextplayer = true;

        $cards = $this->game->cards->pickItems($draw_count, 'deck', ['hand', $currentPlayerId])->values();

        $this->bga->globals->set('draw_count', 0);
        $this->bga->globals->set('drew', $draw_count);

        $this->game->notify->all(
            'DrawCards',
            clienttranslate('${player_name} takes card(s) from the deck'),
            [
                'deck' => $this->game->cards->countItemsInLocation('deck'),
                'player_name' => $this->game->getPlayerNameById($currentPlayerId),
                'player_ids' => [$currentPlayerId],
                'player_' . $currentPlayerId . '_hand' => $this->game->cards->countItemsInLocation(['hand', $currentPlayerId]),
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
        if ($this->game->getPlayerNoById($currentPlayerId) != $this->bga->globals->get('active_player_no')) return null; // can only play before drawing

        $discards = $this->game->cards->getItemsInLocation('discard');
        $top = $this->game->cards->getItemOnTop('discard');
        $playedCards = [];

        $cards = $this->game->cards->getItemsByIds($cardIds)->values();
        if ($this->bga->globals->get('drew') == 1 and count($cards) > 1) {
            $this->game->notify->player(
                $currentPlayerId,
                'InvalidPlay',
                'You cannot play more than one card after drawing',
                []
            );
            return null;
        }
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

        $draw = $this->bga->globals->get('draw');
        $skip = $this->bga->globals->get('skip');
        $suit_demand = $this->bga->globals->get('suit_demand');
        $rank_demand = $this->bga->globals->get('rank_demand');
        $last_jack = $this->bga->globals->get('last_jack');

        for ($i = 0; $i < count($cards); $i++) {
            $card = $cards[$i];
            if ($card->rank == 2 || $card->rank == 3) {
                $draw += $card->rank;
            }
            if ($card->rank == 4) {
                $skip += 1;
            }
        }
        $this->bga->globals->set('draw', $draw);
        $this->bga->globals->set('skip', $skip);
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
                'draw' => $draw,
                'skip' => $skip,
                'suit_demand' => $suit_demand,
                'rank_demand' => $rank_demand,
                'last_jack' => $last_jack,
                'player_ids' => [$currentPlayerId],
                "hand_{$currentPlayerId}" => $this->game->cards->countItemsInLocation(['hand', $currentPlayerId]),
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
