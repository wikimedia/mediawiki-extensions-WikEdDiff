<?php
/**
 * wikEdDiff: inline-style difference engine with block move support
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup DifferenceEngine
 * @ingroup wikEdDiff
 * @author Cacycle (https://en.wikipedia.org/wiki/User:Cacycle)
 */


global $wgExtensionCredits, $wgResourceModules, $wgHooks;

// extension credits
$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'wikEdDiff',
	'author' => 'Cacycle',
	'url' => 'https://www.mediawiki.org/wiki/Extension:wikEdDiff',
	'descriptionmsg' => 'wiked-diff-desc',
	'version' => '1.2.4',
	'license-name' => 'GPL-2.0+' // GNU General Public License v2.0 or later
);

// hook up
$dir = __DIR__ . '/';
$wgResourceModules['ext.wikEdDiff'] = array(
	'localBasePath' => $dir . 'modules',
	'remoteExtPath' => 'WikEdDiff/modules',
	'scripts' => 'ext.wikEdDiff.js',
	'styles' => 'ext.wikEdDiff.css',
	'position' => 'top'
);
$wgMessagesDirs['WikEdDiff'] = $dir . 'i18n';
$wgExtensionMessagesFiles['WikEdDiff'] =  $dir . 'WikEdDiff.i18n.php';
$wgAutoloadClasses['WikEdDiff'] = $dir . 'WikEdDiff.body.php';
$wgHooks['GenerateTextDiffBody'][] = 'WikEdDiff::onGenerateTextDiffBody';
