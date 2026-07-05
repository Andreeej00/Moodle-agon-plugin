@mod @mod_agon @javascript
Feature: Agon play and monitor flows
  In order to revise competitively and to monitor the class
  As a student and a teacher
  I need the full game run, the monitor view and the Question bank to work

  Background:
    Given the following "courses" exist:
      | fullname    | shortname |
      | Agon Course | agonc     |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Student   | One      |
      | teacher1 | Teacher   | Won      |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | agonc  | student        |
      | teacher1 | agonc  | editingteacher |
    And the following "activities" exist:
      | activity | course | name       | idnumber | contentcrossword                                                                                        | contentquestion                                                                                                                                                | contentcoding                                                                                                                          |
      | agon     | agonc  | Agon week1 | agon1    | {"topic":"Tokenization","words":[{"number":1,"word":"CAT","clue":"A pet","direction":"across","row":0,"col":0}]} | {"questions":[{"question":"Which process splits text?","options":["Tokenization","Lemmatization","Parsing","Stemming"],"correct":0,"explanation":"It splits text into tokens."}]} | {"sequences":[{"title":"Split","code":"tokens = text.____()","blanks":["split"],"options":["split","lower","join"],"explanation":"split() tokenizes."}]} |

  Scenario: A student plays the whole run and reaches the leaderboard
    Given I am on the "Agon week1" "agon activity" page logged in as "student1"
    And I should see "Today's challenge"
    And I should see "Three mini-games"
    # Game 1: crossword — click the first cell, type the word, submit.
    When I click on "#cta" "css_element"
    Then I should see "Fill in the words from the clues"
    And I should see "A pet"
    When I click on ".cw__cell[data-rc='0-0'] input" "css_element"
    And I type "CAT"
    And I click on "#cta" "css_element"
    Then I should see "All words correct"
    And I should see "1.00"
    # Game 2: weekly question — gated until Start; answer then confirm.
    When I click on "#cta" "css_element"
    Then I should see "The question is hidden until you're ready"
    When I click on "#start-q" "css_element"
    Then I should see "Which process splits text?"
    When I click on "Tokenization" "button"
    And I click on "#cta" "css_element"
    Then I should see "Correct!"
    And I should see "It splits text into tokens."
    # Game 3: coding — gated; the sequence lazy-loads, place the chip, submit.
    When I click on "#cta" "css_element"
    And I click on "#start-code" "css_element"
    Then I should see "tokens = text."
    When I click on "split" "button"
    And I click on ".blank" "css_element"
    And I click on "#cta" "css_element"
    Then I should see "1 of 1 blanks correct"
    # Review screen (Explain is on by default), then the results.
    When I click on "#cta" "css_element"
    Then I should see "Correct sequence"
    And I should see "split() tokenizes."
    When I click on "#cta" "css_element"
    Then I should see "Today's score"
    And I should see "3.00"
    And I should see "Student One"

  Scenario: A student resumes into the first unplayed game and keeps scores
    Given I am on the "Agon week1" "agon activity" page logged in as "student1"
    When I click on "#cta" "css_element"
    And I click on ".cw__cell[data-rc='0-0'] input" "css_element"
    And I type "CAT"
    And I click on "#cta" "css_element"
    And I should see "All words correct"
    # Reload mid-run: the crossword is already banked, so the run resumes
    # at the weekly question gate, not at the start screen.
    And I reload the page
    Then I should see "The question is hidden until you're ready"
    And I should not see "Today's challenge"

  Scenario: The teacher gets the monitor, not the games, and reaches the Question bank
    Given I am on the "Agon week1" "agon activity" page logged in as "teacher1"
    Then I should see "Agon week1 — overview"
    And I should see "Student attempts"
    And I should see "No attempts yet"
    And I should see "Course leaderboard"
    And I should not see "Today's challenge"
    When I follow "Question bank"
    Then I should see "Generate with AI"
    And I should see "Crossword"
    And I should see "Weekly question"
    And I should see "Coding"
    And I should see "1 word" in the ".bank-panel[data-game='crossword']" "css_element"

  Scenario: The teacher saves crossword content from the bank
    Given I am on the "Agon week1" "agon activity" page logged in as "teacher1"
    When I follow "Question bank"
    And I click on "Build & preview" "button" in the ".bank-panel[data-game='crossword']" "css_element"
    Then I should see "Fix:" in the ".bank-panel[data-game='crossword']" "css_element"
    When I click on "+ Add word" "button"
    And I click on "+ Add word" "button"
    And I set the field with xpath "(//*[@data-game='crossword']//input[@data-w])[1]" to "TOKEN"
    And I set the field with xpath "(//*[@data-game='crossword']//input[@data-c])[1]" to "A unit of text"
    And I set the field with xpath "(//*[@data-game='crossword']//input[@data-w])[2]" to "CORPUS"
    And I set the field with xpath "(//*[@data-game='crossword']//input[@data-c])[2]" to "A body of text"
    And I set the field with xpath "(//*[@data-game='crossword']//input[@data-w])[3]" to "STEM"
    And I set the field with xpath "(//*[@data-game='crossword']//input[@data-c])[3]" to "A word root"
    And I click on "Save crossword" "button"
    Then I should see "Saved." in the ".bank-panel[data-game='crossword']" "css_element"
