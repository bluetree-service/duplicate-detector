# Change Log

## 0.4.0 - 2021-1
### Added
* Auto remove duplicated files
* Delete policy rules
* do doc:
* jeśli brak polityki zostawia pierwszy z listy, resztę kasuje
* jeśli tylko polityka keep, to zostawia te z keep policy i resztę procesuje normalnie (jeśli wykryje że żaden plik nie został zachowany)
* jeśli obie polityki, to przetwarza obie i zostawia te z keep i kasuje te z delete
* jeśli tylko polityka delete, kasuje tylko te z plityki delete
* możliwa symulacja tego co się zadzieje (bez kasowania)
* możliwa kopia bezpieczeństwa przed skasowaniem (tylko tych co mają zostać skasowane)
* *kolejność ruli wg pliku z regułami
### Changed
* Reduce container weight
* Changed building procedure

## 0.3.1.1 - 2021-10-05
### Changed
* Reduce container weight
* Changed building procedure
### Removed
* Removed some unused libraries

## 0.3.1.0 - 2021-05-02
### Changed
* Hash file directory
* Remove some files from docker image
* Refactored Dockerfile

## 0.3.0.1 - 2020-11-29
### Added
* Composer install to image build process

## 0.3.0.0 - 2020-11-02
### Changed
* Use an alpine version to reduce image weight

## 0.2.1.0 - 2020-10-25
### Changed
* Fixed detector entrypoint in docker file

## 0.2.0.0 - 2020-10-25
### Deleted
* Some unused classes
* Unused part of code & comments
### Changed
* Code style
* Minimal PHP version for 7.4
* Entrypoint from `duplicate-detector` to `detector`
### Added
* This changelog
* Declare strict types

## 0.1.0.0 - 2020-10-25
### Added
* Basic version of duplicate detector
