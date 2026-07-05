@mod @mod_agon
Feature: Agon guard rails
  In order not to waste a one-shot attempt on an empty activity
  As a student
  I need unconfigured activities to say so instead of starting a run

  Background:
    Given the following "courses" exist:
      | fullname    | shortname |
      | Agon Course | agonc     |
    And the following "users" exist:
      | username | firstname | lastname |
      | student1 | Student   | One      |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | agonc  | student |

  Scenario: An activity with no playable content shows a notice instead of the run
    Given the following "activities" exist:
      | activity | course | name       | idnumber |
      | agon     | agonc  | Agon empty | agon0    |
    When I am on the "Agon empty" "agon activity" page logged in as "student1"
    Then I should see "This activity has no playable game content yet"
    And I should not see "Today's challenge"

  @javascript
  Scenario: A game whose toggle is off is not part of the run
    Given the following "activities" exist:
      | activity | course | name      | idnumber | gamecrossword | gamecoding | contentquestion                                                                       |
      | agon     | agonc  | Agon qonly | agonq   | 0             | 0          | {"questions":[{"question":"Only game?","options":["Yes","No"],"correct":0}]}          |
    When I am on the "Agon qonly" "agon activity" page logged in as "student1"
    Then I should see "Today's challenge"
    And I should see "Question" in the "#stepper" "css_element"
    And I should not see "Crossword" in the "#stepper" "css_element"
    And I should not see "Code" in the "#stepper" "css_element"
