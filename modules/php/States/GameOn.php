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

    function onEnteringState()
    {
        $this->gamestate->setAllPlayersMultiactive();
    }

    #[PossibleAction]
    public function actPass(int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        $game = $this->game;

        if ($currentPlayerId != $globals->get('fwPlayerId'))
            return $game->err($currentPlayerId, 'not your turn');

        $skip = $globals->get("skip{$currentPlayerId}") + $globals->get('skip');

        // I have more skips to skip
        if ($skip > 0) {
            $globals->set("skip{$currentPlayerId}", $skip - 1);
            $globals->set('skip', 0);
        }

        // carry draws forward
        $draw = $globals->get("draw{$currentPlayerId}") + $globals->get('draw');
        if ($draw > 0) {
            $globals->set("draw{$currentPlayerId}", $draw);
            $globals->set('draw', 0);
        }

        if (!$skip && $draw) return $game->err($currentPlayerId, 'can\'t pass when you have to draw');
        $globals->set('suitDemand', 0);
        $fwPlayerId = $this->nextPlayerId($currentPlayerId);
        $globals->set('fwPlayerId', $fwPlayerId);

        $game->notify->all(
            'Pass',
            clienttranslate('${playerName} passes'),
            [
                'playerId' => $currentPlayerId,
                'playerName' => $game->getPlayerNameById($currentPlayerId),
                'fwPlayerId' => $fwPlayerId,
                'skip' => $skip,
                'draw' => $draw,
            ]
        );
    }

    function bwDraw(int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        $game = $this->game;
        $cards = $game->cards;

        $draw = $globals->get('bwDraw');
        $globals->set('bwDraw', 0);
        $globals->set('bwPlayerId', 0);
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

        $game->notify->all(
            'DrawCards',
            clienttranslate('${playerName} takes card(s) from the deck'),
            $args
        );
    }

    #[PossibleAction]
    public function actDraw(int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        $game = $this->game;
        $cards = $game->cards;

        // TODO: it's possible I'm both the forward and backward player, how to handle?
        if ($globals->get('bwPlayerId')) return bwDraw($currentPlayerId);

        $fwPlayerId = $globals->get('fwPlayerId');

        if ($currentPlayerId != $fwPlayerId) return $game->err($currentPlayerId, 'not your turn');

        $draw = $globals->get('draw');

        if ($globals->get('skip') + $globals->get("skip{$currentPlayerId}") > 0) return $game->err($currentPlayerId, 'have to skip');
        if ($globals->get('suitDemand') > 0) return $game->err($currentPlayerId, 'have to match suit');

        if ($globals->get('drew')) return $game->err($currentPlayerId, 'can only draw once');
        $forcedDraw = false;
        $draw += $globals->get("draw{$currentPlayerId}");
        if ($draw == 0) $draw = 10;
        else $forcedDraw = true;

        $drawnCards = $cards->pickItems($draw, 'deck', ['hand', $currentPlayerId])->values();

        $globals->set('drew', $draw);

        $args = [
            'deck' => $cards->countItemsInLocation('deck'),
            'playerName' => $game->getPlayerNameById($currentPlayerId),
            'playerIds' => [$currentPlayerId],
            'draw' => $draw,
            "hand{$currentPlayerId}" => $cards->countItemsInLocation(['hand', $currentPlayerId]),
            '_private' => [
                $currentPlayerId => [
                    'cards' => $drawnCards
                ]
            ]
        ];

        if ($forcedDraw) {
            $globals->set('draw', 0);
            $globals->set("draw{$currentPlayerId}", 0);

            $fwPlayerId = $this->nextPlayerId($currentPlayerId);
            $args['fwPlayerId'] = $fwPlayerId;
            $globals->set('fwPlayerId', $fwPlayerId);
        }

        $game->notify->all(
            'DrawCards',
            clienttranslate('${playerName} takes card(s) from the deck'),
            $args
        );
    }

    public function bwPlay(#[IntArrayParam()] array $cardIds, int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        $game = $this->game;
        $cards = $game->cards;

        if ($currentPlayerId != $globals->get('bwPlayerId')) return $game->err($currentPlayerId, 'not current player');

        if ($globals->get("draw{$currentPlayerId}") > 0) return $game->err($currentPlayerId, 'you cannot play');

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

        $draw = $globals->get('draw');
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
                $globals->set('draw', $draw);
            } else {
                return $game->err($currentPlayerId, 'you must draw');
            }
        }

        // valid play, move the cards to the discard pile
        // one at a time to maintain order
        for ($i = 0; $i < count($playedCards); $i++) {
            $cards->moveItem($playedCards[$i], 'discard');
        }

        $game->notify->all('BWPlayCards', '', [
            'cards' => $playedCards,
            'bwPlayerId' => $this->prevPlayerId($currentPlayerId),
            "hand{$currentPlayerId}" => $cards->countItemsInLocation(['hand', $currentPlayerId]),
        ]);
    }

    #[PossibleAction]
    public function actPlay(#[IntArrayParam()] array $cardIds, int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        $game = $this->game;
        $cards = $game->cards;

        if ($currentPlayerId == $globals->get('bwPlayerId')) return $this->bwPlay($cardIds, $currentPlayerId);
        if ($currentPlayerId != $globals->get('fwPlayerId')) return $game->err($currentPlayerId, 'not your turn');

        if ($globals->get("draw{$currentPlayerId}") > 0) return $game->err($currentPlayerId, 'you cannot play');
        if ($globals->get("skip{$currentPlayerId}") > 0) return $game->err($currentPlayerId, 'you cannot play');

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
        $drawPlayed = false;
        $skip = $globals->get('skip');
        $skipPlayed = false;
        $suitDemand = $globals->get('suitDemand');
        $rankDemand = $globals->get('rankDemand');
        $lastJack = $globals->get('lastJack');
        $rankpick = 0;
        $suitpick = 0;
        $fwPlayerId = $this->nextPlayerId($currentPlayerId);
        $bwPlayerId = 0;
        $bwdraw = 0;

        for ($i = 0; $i < count($playedCards); $i++) {
            $card = $playedCards[$i];
            if ($card->rank == 2 || $card->rank == 3) {
                $draw += $card->rank;
                $drawPlayed = true;
            }
            if ($card->rank == 13 && $card->suit == 1) {
                // KS prev draw 5
                $bwdraw = 5;
                $bwPlayerId = $this->prevPlayerId($currentPlayerId);
                $drawPlayed = true;
            }
            if ($card->rank == 13 && $card->suit == 2) {
                // KH draw 5
                $draw += 5;
                $drawPlayed = true;
            }
            if ($card->rank == 4) {
                $skip += 1;
                $skipPlayed = true;
            }
            if ($card->rank == 11) {
                // J demands rank
                $rankpick = $currentPlayerId;
                $fwPlayerId = $currentPlayerId;
            }
            if ($card->rank == 14) {
                // A demands suit
                $suitpick = $currentPlayerId;
                $fwPlayerId = $currentPlayerId;
            }
        }

        if ($draw > 0) {
            if ($drawPlayed) $globals->set('draw', $draw);
            else return $game->err($currentPlayerId, 'you must draw');
        }

        if ($skip > 0) {
            if ($skipPlayed) $globals->set('skip', $skip);
            else return $game->err($currentPlayerId, 'you must pass');
        }

        // valid play, move the cards to the discard pile
        // one at a time to maintain order
        for ($i = 0; $i < count($playedCards); $i++) {
            $cards->moveItem($playedCards[$i], 'discard');
        }

        if ($suitDemand) {
            $globals->set('suitDemand', 0);
            $rankDemand = 0;
        }


        $game->notify->all('PlayCards', '', [
            'playerId' => $currentPlayerId,
            'cards' => $playedCards,
            'draw' => $draw,
            'bwdraw' => $bwdraw,
            'skip' => $skip,
            'suitDemand' => $suitDemand,
            'rankDemand' => $rankDemand,
            'rankpick' => $rankpick,
            'suitpick' => $suitpick,
            'lastJack' => $lastJack,
            'fwPlayerId' => $fwPlayerId,
            'bwPlayerId' => $bwPlayerId,
            'handSize' => $cards->countItemsInLocation(['hand', $currentPlayerId]),
        ]);
    }

    #[PossibleAction]
    public function actPickRank(int $rank, int $currentPlayerId)
    {
        // TODO: how to identify if the player is picking
        $globals = $this->bga->globals;
        if ($globals->get('rankpick') != $currentPlayerId) return null;

        $globals->set('rankDemand', $rank);
        $globals->set('lastJack', $currentPlayerId);

        $this->game->bga->notify->all('RankDemand', '', [
            'lastJack' => $currentPlayerId,
            'rankDemand' => $rank,
        ]);
    }

    #[PossibleAction]
    public function actPickSuit(int $suit, int $currentPlayerId)
    {
        $globals = $this->bga->globals;
        if ($globals->get('suitpick') != $currentPlayerId) return null;

        $globals->set('suitDemand', $suit);

        $this->game->bga->notify->all('SuitDemand', '', [
            'suit' => $suit,
        ]);
    }

    public function zombie(int $playerId)
    {
        // We must implement this so BGA can auto play in the case a player becomes a zombie, but for this tutorial we won't handle this case
        throw new UserException("Not implemented: zombie for player ${playerId}");
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
    function getPlayableCards($top, $cards, $currentPlayerId): array
    {
        $globals = $this->bga->globals;
        $matchingCards = [];
        $rankDemand = $globals->get('rankDemand');
        $suitDemand = $globals->get('suitDemand');
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
                    //                    || $card->rank == 13 && $card->suit == 1 // KS
                    || $card->rank == 13 && $card->suit == 2 // KH
                )
            )
                $playable = true;

            if (
                $rankDemand > 0
                &&
                (
                    $card->rank == $rankDemand
                    || $card->rank == 11
                )
            )
                $playable = true;

            if (
                $suitDemand > 0
                && $card->suit == $suitDemand
            )
                $playable = true;

            if (
                $skip == 0
                && $draw == 0
                && $rankDemand == 0
                && $suitDemand == 0
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
