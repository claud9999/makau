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
        $globals = $this->bga->globals;
        $game = $this->game;

        if ($currentPlayerId != $globals->get('active_player_id'))
            return $game->err($currentPlayerId, 'can\'t pass');

        $skip = $globals->get("skip_{$currentPlayerId}") + $globals->get('skip');

        // I have more skips to skip
        if ($skip > 0) {
            $globals->set("skip_{$currentPlayerId}", $skip - 1);
            $globals->set('skip', 0);
        }

        // carry draws forward
        $draw = $globals->get("draw_{$currentPlayerId}") + $globals->get('draw');
        if ($draw > 0) {
            $globals->set("draw_{$currentPlayerId}", $draw);
            $globals->set('draw', 0);
        }

        if (!$skip && $draw) return $game->err($currentPlayerId, 'can\'t pass when you have to draw');

        $game->notify->all(
            'Pass',
            clienttranslate('${player_name} passes'),
            [
                'player_id' => $currentPlayerId,
                'player_name' => $game->getPlayerNameById($currentPlayerId),
                'skip' => $skip,
                'draw' => $draw,
            ]
        );

        return NextPlayer::class;
    }

    #[PossibleAction]
    public function actDraw(int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        $game = $this->game;
        $cards = $game->cards;

        if ($globals->get('skip') + $globals->get("skip_{$currentPlayerId}") > 0) return $game->err($currentPlayerId, 'have to skip');

        if ($currentPlayerId != $globals->get('active_player_id'))
            return $game->err($currentPlayerId, 'not your turn');

        if ($globals->get('drew')) return $game->err($currentPlayerId, 'can only draw once');
        $forcedDraw = false;
        $draw = $globals->get("draw_{$currentPlayerId}") + $globals->get('draw');
        if ($draw == 0) $draw = 10;
        else $forcedDraw = true;

        $drawnCards = $cards->pickItems($draw, 'deck', ['hand', $currentPlayerId])->values();

        if ($forcedDraw > 0) {
            $globals->set('draw', 0);
            $globals->set("draw_{$currentPlayerId}", 0);
        }

        $globals->set('drew', $draw);

        $game->notify->all(
            'DrawCards',
            clienttranslate('${player_name} takes card(s) from the deck'),
            [
                'deck' => $cards->countItemsInLocation('deck'),
                'player_name' => $game->getPlayerNameById($currentPlayerId),
                'player_ids' => [$currentPlayerId],
                'drew' => $draw,
                "hand_{$currentPlayerId}" => $cards->countItemsInLocation(['hand', $currentPlayerId]),
                '_private' => [
                    $currentPlayerId => [
                        'cards' => $drawnCards
                    ]
                ]
            ]
        );

        if ($forcedDraw) return NextPlayer::class; // when forced to draw, I am done
        return null;
    }

    #[PossibleAction]
    public function actPlay(#[IntArrayParam()] array $cardIds, int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        $game = $this->game;
        $cards = $game->cards;

        if ($currentPlayerId != $globals->get('active_player_id')) return null; // can only play before drawing
        if ($globals->get("draw_{$currentPlayerId}") > 0) return $game->err($currentPlayerId, 'you cannot play');
        if ($globals->get("skip_{$currentPlayerId}") > 0) return $game->err($currentPlayerId, 'you cannot play');

        $discards = $cards->getItemsInLocation('discard');
        $top = $cards->getItemOnTop('discard');
        $playedCards = [];

        for ($i = 0; $i < count($cardIds); $i++) {
            $playedCards[] = $cards->getItemById($cardIds[$i]);
        }

        $playableCards = $this->getPlayableCards($top, $playedCards, $currentPlayerId);

        if (count($playableCards) < count($playedCards)) {
            return $game->err(
                $currentPlayerId,
                'You cannot play ' . $this->getCardName($playedCards[count($playableCards)])
            );
        }

        $draw = $globals->get('draw');
        $draw_add = false;
        $skip = $globals->get('skip');
        $skip_add = false;
        $suit_demand = $globals->get('suit_demand');
        $rank_demand = $globals->get('rank_demand');
        $last_jack = $globals->get('last_jack');
        $jack = false;

        for ($i = 0; $i < count($playedCards); $i++) {
            $card = $playedCards[$i];
            if ($card->rank == 2 || $card->rank == 3) {
                $draw += $card->rank;
                $draw_add = true;
            }
            if ($card->rank == 4) {
                $skip += 1;
                $skip_add = true;
            }
            if ($card->rank == 11) { // J demands rank
                $jack = true;
            }
        }

        if ($draw > 0) {
            if ($draw_add) $globals->set('draw', $draw);
            else return $game->err($currentPlayerId, 'you must draw');
        }

        if ($skip > 0) {
            if ($skip_add) $globals->set('skip', $skip);
            else return $game->err($currentPlayerId, 'you must pass');
        }

        // valid play, move the cards to the discard pile
        // one at a time to maintain order
        for ($i = 0; $i < count($playedCards); $i++) {
            $cards->moveItem($playedCards[$i], 'discard');
        }

        $game->notify->all('PlayCards', '', [
            'cards' => $playedCards,
            'draw' => $draw,
            'skip' => $skip,
            'suit_demand' => $suit_demand,
            'rank_demand' => $rank_demand,
            'last_jack' => $last_jack,
            'player_ids' => [$currentPlayerId],
            "hand_{$currentPlayerId}" => $cards->countItemsInLocation(['hand', $currentPlayerId]),
        ]);

        if ($jack) return PickRank::class;

        return NextPlayer::class;
    }

    public function zombie(int $playerId)
    {
        // We must implement this so BGA can auto play in the case a player becomes a zombie, but for this tutorial we won't handle this case
        throw new UserException("Not implemented: zombie for player ${player_id}");
    }

    /* Logic:
    If there is a "draw demand", the only cards the current player can play is another draw card.
    If there is a "skip demand", the only cards the current player can play is another skip card.
    If the previous card was an A and a suit was called, only that suit can be played. If "Free" was called, any non-action card.
    If the a player played a J and a rank was called, only that rank can be played, or another J. If "Any" was called, any non-action card or another J.
    If no skip and no draw demanded, play matching suit or rank or a wild card (A, Q).

    Jokers, when played, are declared as to what kind of card they represent.

    Should match getPlayableCards in Game.js
    */
    function getPlayableCards($top, $cards, $currentPlayerId): array
    {
        $globals = $this->bga->globals;
        $matchingCards = [];
        $rank_demand = $globals->get('rank_demand');
        $draw = $globals->get('draw');
        $skip = $globals->get('skip');

        for ($i = 0; $i < count($cards); $i++) {
            $card = $cards[$i];
            $playable = false;

            if (
                $skip > 0
                && $card->rank == 4
            ) $playable = true;


            if (
                $draw > 0
                && (
                    $card->rank == 2
                    || $card->rank == 3
                    || $card->rank == 13 // king
                )
            )
                $playable = true;

            if (
                $rank_demand > 0
                &&
                (
                    $card->rank == $rank_demand
                    || $card->rank == 11
                )
            )
                $playable = true;

            if (
                $skip == 0
                && $draw == 0
                && $rank_demand == 0
                &&
                (
                    $card->rank == 12 // queen
                    || $card->suit == $top->suit
                    || $card->rank == $top->rank
                    || $top->rank == 12 // queen
                )
            )
                $playable = true;

            if ($playable) {
                $matchingCards[] = $card;
                $top = $card;
            }
        }

        return $matchingCards;
    }

    function getCardName($card): string
    {
        return ('The ' . $this->game->card_types['ranks'][$card->rank]['name'] . " of " . $this->game->card_types['suits'][$card->suit]['name'] . 's');
    }

    function getCardNames($cards): string
    {
        return implode(", ", array_map(fn($card) => $this->getCardName($card), $cards));
    }
}
