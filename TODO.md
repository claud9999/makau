# TODO

* allow clicking on the deck to draw a card
* disable unplayable cards (esp when not the active player)
* tooltips/graphics to indicate special cards
* enforce rules
* test 3 and 4 players

# BUGS

* only allow one sort of demand (draw, skip, suit, or rank)

# scenarios

## One
Discard: 7H
P1: hand is xxx, plays 2H 2S 4C
P2: hand is xxx, 4H -- can't

## Two
Discard: 7H
P1: hand is 9H, 3S, etc., draws 9S, can he play 9H, 9S, 3S or does he have to play 9S first since he just drew it?

## Three
Discard: 7H
P1: plays 2H, 4H
P2: wants to play 4D, 4S, does he need to play a draw card (2, 3, K) as well?