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
    public array $st;

    public function __construct(protected Game $game)
    {
        parent::__construct(
            $game,
            id: 31,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
            descriptionMyTurn: clienttranslate('${you} may play cards or draw')
        );
    }

    function onEnteringState()
    {
        $this->gamestate->setAllPlayersMultiactive();
    }

    #[PossibleAction]
    public function actPass(int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        $game = $this->game;
        $st = json_decode($this->bga->globals->get('state'), true);

        if ($currentPlayerId != $st['fwPlayerId'])
            return $game->err($currentPlayerId, 'not your turn');

        $skip = $st["skip{$currentPlayerId}"] + $st['skip'];

        // I have more skips to skip
        if ($skip > 0) {
            $st["skip{$currentPlayerId}"] = $skip - 1;
            $st['skip'] = 0;
        }

        // carry draws forward
        $draw = $st["draw{$currentPlayerId}"] + $st['draw'];
        if ($draw > 0) {
            $st["draw{$currentPlayerId}"] = $draw;
            $st['draw'] = 0;
        }

        if (!$skip && $draw) return $game->err($currentPlayerId, 'can\'t pass when you have to draw');

        $st['suitDemand'] = 0;

        $fwPlayerId = $this->nextPlayerId($currentPlayerId);
        $st['fwPlayerId'] = $fwPlayerId;
        $st['drew'] = 0;

        $globals->set('state', json_encode($st));

        $st['playerId'] = $currentPlayerId;
        $st['playerName'] = $game->getPlayerNameById($currentPlayerId);

        $game->notify->all(
            'Pass',
            clienttranslate('${playerName} passes'),
            $st,
        );
    }

    function bwDraw(int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        $game = $this->game;
        $cards = $game->cards;
        $st = json_decode($this->bga->globals->get('state'), true);

        $draw = $st['bwDraw'];
        $st['bwDraw'] = 0;
        $st['bwPlayerId'] = 0;
        $drawnCards = $cards->pickItems($draw, 'deck', ['hand', $currentPlayerId])->values();

        $args = [
            'deck' => $cards->countItemsInLocation('deck'),
            'playerName' => $game->getPlayerNameById($currentPlayerId),
            'playerIds' => [$currentPlayerId],
            'bwPlayerId' => 0,
            'draw' => $draw,
            "hand{$currentPlayerId}" => $cards->countItemsInLocation(['hand', $currentPlayerId]),
            '_private' => [
                $currentPlayerId => [
                    'cards' => $drawnCards
                ]
            ]
        ];

        $globals->set('state', json_encode($st));

        $st['playerId'] = $currentPlayerId;

        $game->notify->all(
            'DrawCards',
            clienttranslate('${playerName} takes card(s) from the deck'),
            $st,
        );
    }

    #[PossibleAction]
    public function actDraw(int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        $game = $this->game;
        $cards = $game->cards;
        $st = json_decode($this->bga->globals->get('state'), true);

        // TODO: it's possible I'm both the forward and backward player, how to handle?
        if ($st['bwPlayerId']) return bwDraw($currentPlayerId);

        if ($currentPlayerId != $st['fwPlayerId']) return $game->err($currentPlayerId, 'not your turn');

        if ($st['skip'] + $st["skip{$currentPlayerId}"] > 0) return $game->err($currentPlayerId, 'have to skip');
        if ($st['suitDemand'] > 0) return $game->err($currentPlayerId, 'have to match suit');

        if ($st['drew'] > 0) return $game->err($currentPlayerId, 'can only draw once');
        $forcedDraw = false;
        $draw = $st['draw'] + $st["draw{$currentPlayerId}"];
        if ($draw == 0) $draw = 10;
        else $forcedDraw = true;

        $drawnCards = $cards->pickItems($draw, 'deck', ['hand', $currentPlayerId])->values();

        $st['drew'] = $draw;
        $st['deck'] = $cards->countItemsInLocation('deck');
        $st["hand{$currentPlayerId}"] = $cards->countItemsInLocation(['hand', $currentPlayerId]);

        if ($forcedDraw) {
            $st['draw'] = 0;
            $st["draw{$currentPlayerId}"] = 0;
            $st['drew'] = 0;

            $st['fwPlayerId'] = $this->nextPlayerId($currentPlayerId);
        }

        $globals->set('state', json_encode($st));

        $st['playerId'] = $currentPlayerId;
        $st['playerName'] = $game->getPlayerNameById($currentPlayerId);
        $st['_private'] = [
            $currentPlayerId => [
                'cards' => $drawnCards
            ]
        ];

        $game->notify->all(
            'DrawCards',
            clienttranslate('${playerName} takes card(s) from the deck'),
            $st,
        );
    }

    public function bwPlay(#[IntArrayParam()] array $cardIds, int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        $game = $this->game;
        $cards = $game->cards;
        $st = json_decode($this->bga->globals->get('state'), true);

        if ($currentPlayerId != $st['bwPlayerId']) return $game->err($currentPlayerId, 'not current player');

        if ($st["draw{$currentPlayerId}"] > 0) return $game->err($currentPlayerId, 'you cannot play');

        $discards = $cards->getItemsInLocation('discard');
        $top = $cards->getItemOnTop('discard');
        $playedCards = [];

        for ($i = 0; $i < count($cardIds); $i++) {
            $playedCards[] = $cards->getItemById($cardIds[$i]);
        }

        $playableCards = $this->getDrawCards($playedCards);

        if (count($playableCards) < count($playedCards)) {
            return $game->err(
                $currentPlayerId,
                'You cannot play ' . $this->getCardName($playedCards[count($playableCards)])
            );
        }

        $draw = $st['draw'];
        $drawPlayed = false;

        for ($i = 0; $i < count($playedCards); $i++) {
            $card = $playedCards[$i];
            if ($card->rank == 2 || $card->rank == 3) {
                $draw += $card->rank;
                $drawPlayed = true;
            }
            if ($card->rank == 13) {
                if ($card->suit == 1 || $card->suit == 2) {
                    $draw += 5;
                    $drawPlayed = true;
                } else {
                    $draw = 0;
                    $drawPlayed = 0;
                }
            }
        }

        if ($draw > 0) {
            if ($drawPlayed) {
                $st['draw'] = $draw;
            } else {
                return $game->err($currentPlayerId, 'you must draw');
            }
        }

        // valid play, move the cards to the discard pile
        // one at a time to maintain order
        for ($i = 0; $i < count($playedCards); $i++) {
            $cards->moveItem($playedCards[$i], 'discard');
        }

        $st['bwPlayerId'] = $this->prevPlayerId($currentPlayerId);
        $st["hand{$currentPlayerId}"] = $cards->countItemsInLocation(['hand', $currentPlayerId]);

        $globals->set('state', json_encode($st));

        $st['playerId'] = $currentPlayerId;
        $st['_private'] = [
            $currentPlayerId => [
                'cards' => $drawnCards
            ]
        ];
        $st['cards'] = $playedCards;

        $game->notify->all('BWPlayCards', '', $st);
    }

    #[PossibleAction]
    public function actPlay(#[IntArrayParam()] array $cardIds, int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        $game = $this->game;
        $cards = $game->cards;
        $st = json_decode($globals->get('state'), true);
        if ($st['lastJack'] == $currentPlayerId) {
            $st['lastJack'] = 0;
            $st['rankDemand'] = 0;
        }

        if ($currentPlayerId == $st['bwPlayerId']) return $this->bwPlay($cardIds, $currentPlayerId);
        if ($currentPlayerId != $st['fwPlayerId']) return $game->err($currentPlayerId, 'not your turn');

        if ($st["draw{$currentPlayerId}"] > 0) return $game->err($currentPlayerId, 'you cannot play');
        if ($st["skip{$currentPlayerId}"] > 0) return $game->err($currentPlayerId, 'you cannot play');

        $top = $cards->getItemOnTop('discard');
        $playedCards = [];

        for ($i = 0; $i < count($cardIds); $i++) {
            $playedCards[] = $cards->getItemById($cardIds[$i]);
        }

        $playableCards = $this->getPlayableCards($top, $playedCards, $currentPlayerId, $st);

        if (count($playableCards) < count($playedCards)) {
            return $game->err(
                $currentPlayerId,
                'You cannot play ' . $this->getCardName($playedCards[count($playableCards)])
            );
        }

        $drawPlayed = false;
        $skipPlayed = false;
        $st['fwPlayerId'] = $this->nextPlayerId($currentPlayerId);
        $st['bwDraw'] = 0;

        for ($i = 0; $i < count($playedCards); $i++) {
            $card = $playedCards[$i];
            if ($card->rank == 2 || $card->rank == 3) {
                $st['draw'] += $card->rank;
                $drawPlayed = true;
            }
            if ($card->rank == 13 && $card->suit == 1) {
                // KS prev draw 5
                $st['bwDraw'] = 5;
                $st['bwPlayerId'] = $this->prevPlayerId($currentPlayerId);
                $drawPlayed = true;
            }
            if ($card->rank == 13 && $card->suit == 2) {
                // KH draw 5
                $st['draw'] += 5;
                $drawPlayed = true;
            }
            if ($card->rank == 4) {
                $st['skip'] += 1;
                $skipPlayed = true;
            }
            if ($card->rank == 11) {
                // J demands rank
                $st['rankPick'] = $currentPlayerId;
                $st['lastJack'] = $currentPlayerId;
                $st['fwPlayerId'] = $currentPlayerId;
            }
            if ($card->rank == 14) {
                // A demands suit
                $st['suitPick'] = $currentPlayerId;
                $st['fwPlayerId'] = $currentPlayerId;
            }
        }

        if ($st['draw'] > 0 && !$drawPlayed) return $game->err($currentPlayerId, 'you must draw');
        if ($st['skip'] > 0 && !$skipPlayed) return $game->err($currentPlayerId, 'you must pass');

        // valid play, move the cards to the discard pile
        // one at a time to maintain order
        for ($i = 0; $i < count($playedCards); $i++) {
            $cards->moveItem($playedCards[$i], 'discard');
        }

        if ($st['suitDemand']) {
            $st['suitDemand'] = 0;
        }

        $st['drew'] = 0;

        $globals->set('state', json_encode($st));

        $st['playerId'] = $currentPlayerId;
        $st['cards'] = $playedCards;
        $st['handSize'] = $cards->countItemsInLocation(['hand', $currentPlayerId]);

        $game->notify->all('PlayCards', '', $st);
    }

    #[PossibleAction]
    public function actPickRank(int $rank, int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        $st = json_decode($this->bga->globals->get('state'), true);

        if ($st['rankPick'] != $currentPlayerId) return null;

        $fwPlayerId = $this->nextPlayerId($currentPlayerId);

        $st['rankDemand'] = $rank;
        $st['rankPick'] = 0;
        $st['lastJack'] = $currentPlayerId;
        $st['fwPlayerId'] = $fwPlayerId;

        $globals->set('state', json_encode($st));

        $this->game->bga->notify->all('RankDemand', '', $st);
    }

    #[PossibleAction]
    public function actPickSuit(int $suit, int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        $st = json_decode($this->bga->globals->get('state'), true);

        if ($st['suitPick'] != $currentPlayerId) return null;

        $fwPlayerId = $this->nextPlayerId($currentPlayerId);

        $st['suitDemand'] = $suit;
        $st['suitPick'] = 0;
        $st['fwPlayerId'] = $fwPlayerId;

        $globals->set('state', json_encode($st));

        $this->game->bga->notify->all('SuitDemand', '', $st);
    }

    public function zombie(int $playerId)
    {
        // We must implement this so BGA can auto play in the case a player becomes a zombie, but for this tutorial we won't handle this case
        throw new UserException("Not implemented: zombie for player {$playerId}");
    }

    function getDrawCards($cards): array
    {
        $matchingCards = [];

        for ($i = 0; $i < count($cards); $i++) {
            $card = $cards[$i];

            if (
                $card->rank == 2
                || $card->rank == 3
                //                    || $card->rank == 13 && $card->suit == 1 // KS
                || $card->rank == 13 && $card->suit == 2 // KH
            )
                $matchingCards[] = $card;
        }

        return $matchingCards;
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
    function getPlayableCards($top, $cards, $currentPlayerId, $st): array
    {
        $globals = $this->bga->globals;
        $matchingCards = [];

        for ($i = 0; $i < count($cards); $i++) {
            $card = $cards[$i];
            $playable = false;

            if (
                $st['skip'] > 0
                && $card->rank == 4
            ) $playable = true;

            if (
                $st['draw'] > 0
                && (
                    $card->rank == 2
                    || $card->rank == 3
                    //                    || $card->rank == 13 && $card->suit == 1 // KS
                    || $card->rank == 13 && $card->suit == 2 // KH
                )
            )
                $playable = true;

            if (
                $st['rankDemand'] > 0
                && $st['rankDemand'] < 20 // any
                &&
                (
                    $card->rank == $st['rankDemand']
                    || $card->rank == 11
                )
            )
                $playable = true;

            if (
                $st['suitDemand'] > 0
                && $st['suitDemand'] < 20
                && $card->suit == $st['suitDemand']
                && $card->rank > 4 // only non-action
                && $card->rank < 11
            )
                $playable = true;

            if (
                (
                    $st['rankDemand'] == 20
                    || $st['suitDemand'] == 20
                )
                && $card->rank > 4
                && $card->rank < 11
            )
                $playable = true;

            if (
                $st['skip'] == 0
                && $st['draw'] == 0
                && $st['rankDemand'] == 0
                && $st['suitDemand'] == 0
                &&
                (
                    $card->rank == 14 // A wild
                    || $card->rank == 12 // Q wild
                    || $card->suit == $top->suit
                    || $card->rank == $top->rank
                    || $top->rank == 12 // wild Q
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
        return ('The ' . $this->game->cardTypes['ranks'][$card->rank]['name'] . " of " . $this->game->cardTypes['suits'][$card->suit]['name'] . 's');
    }

    function getCardNames($cards): string
    {
        return implode(", ", array_map(fn($card) => $this->getCardName($card), $cards));
    }

    function nextPlayerId($playerId): int
    {
        $game = $this->game;

        $playerNo = $game->getPlayerNoById($playerId) + 1;
        if ($playerNo > $game->getPlayerCount()) $playerNo = 1;
        return $game->getPlayerIdByNo($playerNo);
    }

    function prevPlayerId($playerId): int
    {
        $game = $this->game;

        $playerNo = $game->getPlayerNoById($playerId) - 1;
        if ($playerNo < 1) $playerNo = $game->getPlayerCount();
        return $game->getPlayerIdByNo($playerNo);
    }
}
