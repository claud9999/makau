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

        if ($game->getPlayerNoById($currentPlayerId) != $globals->get('active_player_no'))
            return null;

        $skip = $globals->get('skip');
        $skip += $globals->get("skip_{$currentPlayerId}");

        // I have more skips to skip
        if ($skip > 0) {
            $globals->set("skip_{$currentPlayerId}", $skip - 1);
            $globals->set('skip', 0);
        }

        $game->notify->all(
            'Pass',
            clienttranslate('${player_name} passes'),
            [
                'player_id' => $currentPlayerId,
                'player_name' => $game->getPlayerNameById($currentPlayerId),
                'player_ids' => [$currentPlayerId],
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

        if ($game->getPlayerNoById($currentPlayerId) != $globals->get('active_player_no'))
            return null;

        if ($globals->get('drew')) return; // can only draw once
        $nextplayer = false;
        $draw = $globals->get('draw');
        if ($draw == 0) $draw = 1;
        else $nextplayer = true;

        $drawnCards = $cards->pickItems($draw, 'deck', ['hand', $currentPlayerId])->values();

        $globals->set('draw', 0);
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

        if ($nextplayer) return NextPlayer::class; // when forced to draw, I am done
        return null;
    }

    #[PossibleAction]
    public function actPlay(#[IntArrayParam()] array $cardIds, int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        $game = $this->game;
        $cards = $game->cards;

        if ($game->getPlayerNoById($currentPlayerId) != $globals->get('active_player_no')) return null; // can only play before drawing

        $discards = $cards->getItemsInLocation('discard');
        $top = $cards->getItemOnTop('discard');
        $playedCards = [];

        for($i = 0; $i < count($cardIds); $i++) {
            $playedCards[] = $cards->getItemById($cardIds[$i]);
        }

        $playableCards = $this->getPlayableCards($top, $playedCards, $currentPlayerId);
        
        if (count($playableCards) < count($playedCards)) {
            $game->notify->player(
                $currentPlayerId,
                'InvalidPlay',
                'You cannot play ' . $this->getCardName($playedCards[count($playableCards)]),
                []
            );
            return null;
        }

        $draw = $globals->get("draw_{$currentPlayerId}");
        $next_draw = false;
        $skip = $globals->get("skip_{$currentPlayerId}");
        $next_skip = false;
        $suit_demand = $globals->get('suit_demand');
        $rank_demand = $globals->get('rank_demand');
        $last_jack = $globals->get('last_jack');
        $jack = false;

        for ($i = 0; $i < count($playedCards); $i++) {
            $card = $playedCards[$i];
            if ($card->rank == 2 || $card->rank == 3) {
                $draw += $card->rank;
                $next_draw = true;
            }
            if ($card->rank == 4) {
                $skip += 1;
                $next_skip = true;
            }
            if ($card->rank == 11) { // J demands rank
                $jack = true;
            }
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
        throw new UserException('Not implemented: zombie for player ${player_id}');
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

        for ($i = 0; $i < count($cards); $i++) {
            $card = $cards[$i];
            if ($globals->get('skip') > 0) {
                if ($card->rank == 4)
                    $matchingCards[] = $card;
                $top = $card;
            } else if ($globals->get('draw') > 0) {
                if (
                    $card->rank == 2
                    || $card->rank == 3
                    || $card->rank == 13 // king
                )
                    $matchingCards[] = $card;
                $top = $card;
            } else {
                if (
                    $card->rank == 12 // queen
                    || $card->suit == $top->suit
                    || $card->rank == $top->rank
                    || $top->rank == 12 // queen
                )
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
