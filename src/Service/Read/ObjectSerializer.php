<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\MCP\Service\Read;

use Altioo\iTop\Extension\MCP\Helper\ObjectId;
use Altioo\iTop\Extension\MCP\Service\Audit\ReadSummary;
use Altioo\iTop\Extension\MCP\Service\Rights\AccessGrantFamilies;
use Altioo\iTop\Extension\MCP\Service\Rights\ExclusionPolicy;
use AttributeBlob;
use AttributeCaseLog;
use AttributeDefinition;
use AttributeExternalField;
use AttributeLinkedSet;
use DBObject;
use DBObjectSet;
use MetaModel;
use ormDocument;
use Throwable;
use UserRights;
use iAttributeNoGroupBy;

/**
 * One object, rendered the way iTop's own REST API renders it.
 *
 * Every tool that returns objects goes through here, for two reasons.
 *
 * The first is correctness. DBObject::Get() returns whatever internal
 * representation the attribute uses - an ormCaseLog for a log, an ormLinkSet
 * for a link set, an ormDocument for an attachment - and none of those
 * implement JsonSerializable, nor expose a single public property. Handing one
 * to json_encode() yields "{}": the ticket log, the CI list and the attachment
 * all arrive empty, silently, with a 200. AttributeDefinition::GetForJSON() is
 * the conversion iTop wrote for exactly this, and is what
 * ObjectResult::MakeResultValue() calls on the REST side.
 *
 * The second is that a response feeds a context window. Four attribute kinds
 * have no natural size and are handled here rather than left to GetForJSON: a
 * blob, whose JSON form embeds the whole file base64-encoded, is reported as
 * its metadata and the URI that serves it; a case log, a link set and a long
 * text are cut to a ceiling that the value itself declares.
 *
 * None of those ceilings applies when the caller named the attributes it wants
 * through output_fields. Asking for one attribute by name is the decision to
 * read it in full; the ceilings are there so that a broad read cannot spend a
 * context window nobody asked it to spend.
 *
 * @api
 * @since 1.0.0
 */
final class ObjectSerializer
{
	/**
	 * What a sensitive attribute reads as, whatever its type.
	 *
	 * The same five stars core/restservices.class.inc.php writes, so that a
	 * masked value coming out of MCP is indistinguishable from one coming out
	 * of REST - a difference in the mask is a way to tell the two apart, and
	 * nothing gains by it.
	 */
	public const MASK = '*****';

	/**
	 * What a list of objects reports when the caller says nothing, the same
	 * default ObjectResult::FromDBObject() applies on the REST side.
	 */
	public const DEFAULT_LIST_FIELDS = 'id, friendlyname';

	/**
	 * The attributes of $oObject the caller may read.
	 *
	 * @param string             $sClass  The object's final class - the one whose attributes are enumerated.
	 * @param array<int, string>|null $aFields Attribute codes to report; null for all of them.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function Serialize(DBObject $oObject, string $sClass, ?array $aFields = null): array
	{
		// ObjectId::Normalise(), because a write already reports the id through it and
		// a read that answers "4" where a create answered 4 is one surface with
		// two types in it - the caller that pastes the first into the second is
		// the caller this module has already had refused by its own schema.
		$aData = [MetaModel::DBGetKey($sClass) => ObjectId::Normalise($oObject->GetKey())];

		// The audit row says which objects left, by class and id. Here and not in
		// each tool, because this is where an object becomes an answer.
		ReadSummary::Note($sClass, (int)$oObject->GetKey());

		// Every object says what it is, under the key core_object_find_by_name
		// and core_object_get_related already used. A search on Ticket returns
		// UserRequests and Incidents side by side, and without it nothing in a
		// row tells them apart. It was once keyed on DBGetClassField(), which
		// iTop answers '' for a leaf class - and every fetched object is one -
		// so dropping the '' key dropped the class with it.
		$aData['class'] = $sClass;

		$aUnreadable = [];

		// Built on first use and only when some attribute answers DEPENDS, so
		// an install whose addon never does - the shipped one never does - pays
		// nothing for the query.
		$oInstanceSet = null;

		// Whether this object is out of circulation, on every read that
		// returns it.
		//
		// Two answers, because iTop has two notions. Archived is soft
		// deletion, declared per class hierarchy and hidden unless the call
		// asks for it. Obsolete is a condition the datamodel evaluates - a
		// FunctionalCI whose status is 'obsolete' - and it is the one a stock
		// instance actually uses, which makes it the one a set is most likely
		// to be carrying.
		//
		// Both matter here for the same reason: the default field list is id
		// and friendlyname, which describe a live object, an archived one and
		// an obsolete one identically. A caller that asked to see them and
		// cannot tell them apart is worse off than one that never saw them.
		//
		// Neither is conditional on the mode or the preference that let the
		// object into the set. Whether a caller can currently see such objects
		// and whether the object in hand is one are two questions, and
		// answering the second only while the answer is interesting is how a
		// payload teaches a reader to stop looking.
		$aFields = self::withStateFlags($sClass, $aFields);

		foreach (MetaModel::ListAttributeDefs($sClass) as $sAttCode => $oAttDef) {
			if ($aFields !== null && !in_array($sAttCode, $aFields, true)) {
				continue;
			}
			// Absent, not masked: an excluded attribute is not in the answer at all (doc/adr/0012).
			if (ExclusionPolicy::ExcludesAttribute($sClass, (string)$sAttCode)) {
				continue;
			}
			if (!self::MayReadAttribute($oObject, $sClass, $sAttCode, $oInstanceSet)) {
				continue;
			}

			try {
				// Naming the attributes is itself the decision to read them in
				// full: the ceilings exist to keep a broad read from spending
				// a context window that nobody asked it to spend.
				$aData[$sAttCode] = self::Value($oObject, $sClass, $sAttCode, $aFields === null);
			} catch (Throwable $e) {
				// One attribute that cannot be rendered - a dangling external
				// field, a document whose file is gone - must not cost the
				// caller the other forty.
				$aData[$sAttCode] = null;
				$aUnreadable[] = $sAttCode;
			}
		}

		// Three states each, because there are three answers.
		//
		// true and false are iTop's own, on a class that declares the flag. A
		// class that declares neither is not "not archived" and not "not
		// obsolete": archiving is a property of a class hierarchy and
		// obsolescence is a condition the datamodel declares, so a Person or a
		// Team may have no such notion at all - reporting false there would
		// answer a question nobody asked, and a caller filtering on it would
		// drop objects that were never candidates. null says the question does
		// not apply here, the same way the lifecycle block answers null for a
		// class with no states rather than an empty list of transitions.
		//
		// Never overwrites what the loop found, so an attribute the caller may
		// not read keeps whatever the rights layer decided about it.
		foreach (self::STATE_FLAGS as $sFlag) {
			if (!array_key_exists($sFlag, $aData) && !self::HasAttribute($sClass, $sFlag)) {
				$aData[$sFlag] = null;
			}
		}

		if (!empty($aUnreadable)) {
			$aData['_unreadable_attributes'] = $aUnreadable;
		}

		return $aData;
	}

	/**
	 * iTop's own answers about an object being out of circulation, on the
	 * classes that declare them.
	 *
	 * Two different notions and two different mechanisms: archived is soft
	 * deletion, declared per class hierarchy; obsolete is a condition the
	 * datamodel evaluates - "status = 'obsolete'" on a FunctionalCI - and it
	 * is the one a stock instance actually uses.
	 */
	private const ARCHIVE_FLAG = 'archive_flag';
	private const OBSOLESCENCE_FLAG = 'obsolescence_flag';

	private const STATE_FLAGS = [self::ARCHIVE_FLAG, self::OBSOLESCENCE_FLAG];

	/**
	 * The archive and obsolescence flags, added to a narrowed field list.
	 *
	 * A caller that asked for every attribute already has them, and one that
	 * named them already has them. This is for the default - id and
	 * friendlyname - which describes a live object, an archived one and an
	 * obsolete one identically.
	 *
	 * @param array<int, string>|null $aFields Null means every attribute, which already includes both.
	 *
	 * @return array<int, string>|null
	 */
	private static function withStateFlags(string $sClass, ?array $aFields): ?array
	{
		if ($aFields === null) {
			return $aFields;
		}

		foreach (self::STATE_FLAGS as $sFlag) {
			if (!in_array($sFlag, $aFields, true) && self::HasAttribute($sClass, $sFlag)) {
				$aFields[] = $sFlag;
			}
		}

		return $aFields;
	}

	/**
	 * Whether this class declares an attribute at all.
	 *
	 * Guarded: a question about the datamodel must not cost the read that
	 * asked it, and answering "no" leaves the payload as it was before the
	 * flag existed - which for these two means the null that says the question
	 * does not apply here.
	 *
	 * @internal Public only so that {@see UntrustedMarker} can ask it; not part of the surface a pack may call.
	 */
	public static function HasAttribute(string $sClass, string $sAttCode): bool
	{
		try {
			return MetaModel::IsValidAttCode($sClass, $sAttCode);
		} catch (Throwable $e) {
			return false;
		}
	}

	/**
	 * Whether this caller may read this attribute of this object.
	 *
	 * IsActionAllowedOnAttribute() is tri-state. Read as a boolean,
	 * UR_ALLOWED_DEPENDS is 2 and therefore truthy, so an addon saying "ask me
	 * again with the object" was being read as "yes" - and every attribute it
	 * grades per object was served on every read. The shipped addon never
	 * answers DEPENDS for attributes, so nothing changes on a stock install;
	 * under one that does, this is the difference between honouring its answer
	 * and ignoring it.
	 *
	 * The instance set is built once per object and only if some attribute
	 * actually answers DEPENDS, which keeps the query off the common path.
	 *
	 * @param DBObjectSet|null $oInstanceSet Reused across attributes of one object; created here on first need.
	 */
	public static function MayReadAttribute(DBObject $oObject, string $sClass, string $sAttCode, ?DBObjectSet &$oInstanceSet): bool
	{
		$iAllowed = UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_READ);

		if ($iAllowed === UR_ALLOWED_YES) {
			return true;
		}
		if ($iAllowed === UR_ALLOWED_NO) {
			return false;
		}

		// DEPENDS, and the object is what it depends on. An object with no key
		// is not in the database to be asked about, so the question cannot be
		// resolved and the answer stays no.
		$iKey = (int)$oObject->GetKey();
		if ($iKey < 1) {
			return false;
		}

		$oInstanceSet ??= new DBObjectSet(ObjectQuery::ById($sClass, $iKey));

		return UserRights::IsActionAllowedOnAttribute($sClass, $sAttCode, UR_ACTION_READ, $oInstanceSet) === UR_ALLOWED_YES;
	}

	/**
	 * One attribute, in its JSON form.
	 *
	 * @param bool $bClip Whether the unbounded kinds are cut to their ceilings.
	 *
	 * @return mixed A scalar, or a structure of scalars; never an ORM object.
	 * @since 1.0.0
	 */
	public static function Value(DBObject $oObject, string $sClass, string $sAttCode, bool $bClip = true): mixed
	{
		if ($sAttCode === 'id') {
			return ObjectId::Normalise($oObject->GetKey());
		}

		$oAttDef = MetaModel::GetAttributeDef($sClass, $sAttCode);

		if (self::IsSensitive($oAttDef, $sClass)) {
			// Masked before any conversion, so no code path can read the real
			// value into the response.
			return self::MASK;
		}

		if ($oAttDef instanceof AttributeBlob) {
			// GetForJSON() would base64 the whole file into the response.
			return self::document($oObject->Get($sAttCode), $sClass, (int)$oObject->GetKey(), $sAttCode);
		}

		$value = $oAttDef->GetForJSON($oObject->Get($sAttCode));

		if ($oAttDef instanceof AttributeLinkedSet) {
			// Before anything else looks at it, and regardless of $bClip:
			// output_fields decides how much of an object is reported, never
			// what may be read at all.
			$value = self::maskSensitiveLinks($value, $oAttDef);
		}

		if (!$bClip) {
			return $value;
		}

		if ($oAttDef instanceof AttributeCaseLog) {
			return ValueCeilings::clip(ValueCeilings::recentEntries($value));
		}

		if ($oAttDef instanceof AttributeLinkedSet) {
			return ValueCeilings::clip(ValueCeilings::firstLinks($value));
		}

		return ValueCeilings::clip($value);
	}

	/**
	 * Masks the sensitive attributes of every row of a link set.
	 *
	 * A sensitive attribute is masked on the object that carries it. Left
	 * unmasked on a link pointing at it, an attribute the datamodel marks
	 * sensitive on a link class - and the ones reached through it - would come
	 * back in clear to anyone who read the object on the other side of the
	 * link. iTop's REST API masks these too (SanitizeTrait in
	 * core/restservices.class.inc.php).
	 *
	 * Each row is attcode => value on the linked class, so the same
	 * IsSensitive() that decides a top-level attribute decides these - which is
	 * also what covers the n-n case, where the sensitive attribute belongs to
	 * the far class and is reached through an external field. iTop spells that
	 * case out as a separate branch; here it falls out of resolving external
	 * fields in one place.
	 *
	 * @param mixed $value As AttributeLinkedSet::GetForJSON() returns it: a list of attcode => value rows.
	 *
	 * @return mixed
	 */
	private static function maskSensitiveLinks($value, AttributeLinkedSet $oAttDef)
	{
		if (!is_array($value)) {
			return $value;
		}

		$sLinkedClass = $oAttDef->GetLinkedClass();

		foreach ($value as $iRow => $aRow) {
			if (!is_array($aRow)) {
				continue;
			}

			// An extended output names the subclass in finalclass, and a
			// subclass can carry attributes the declared link class does not.
			$sRowClass = isset($aRow['finalclass']) && is_string($aRow['finalclass'])
				&& MetaModel::IsValidClass($aRow['finalclass'])
					? $aRow['finalclass']
					: $sLinkedClass;

			// A row of an excluded class is not in the set at all; an excluded attribute is not in the row.
			if (ExclusionPolicy::ExcludesClass($sRowClass)) {
				unset($value[$iRow]);
				continue;
			}

			foreach (array_keys($aRow) as $sLnkAttCode) {
				if (!MetaModel::IsValidAttCode($sRowClass, $sLnkAttCode)) {
					continue;
				}
				if (ExclusionPolicy::ExcludesAttribute($sRowClass, $sLnkAttCode)) {
					unset($value[$iRow][$sLnkAttCode]);
					continue;
				}

				if (self::IsSensitive(MetaModel::GetAttributeDef($sRowClass, $sLnkAttCode), $sRowClass)) {
					$value[$iRow][$sLnkAttCode] = self::MASK;
				}
			}
		}

		return $value;
	}

	/**
	 * A document reported by what it is, never by what it contains - and by
	 * where the caller can go and get it.
	 *
	 * The `uri` is what keeps this from being a dead end. The bytes stay out of
	 * every read, because a read fans out and a file base64-encoded into one is
	 * a context window spent without anyone choosing to; naming the one document
	 * wanted is the choice, and {@see DocumentAccess} is where it is served.
	 *
	 * @param mixed $value An ormDocument, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function document($value, string $sClass, int $iId, string $sAttCode): ?array
	{
		if (!$value instanceof ormDocument || $value->IsEmpty()) {
			return null;
		}

		return DocumentAccess::Describe($value, $sClass, $iId, $sAttCode);
	}

	/**
	 * Whether an attribute holds something that must not be reported.
	 *
	 * iAttributeNoGroupBy is how the datamodel marks an attribute as
	 * sensitive - it is the interface iTop's own code tests for. The one
	 * definition, so that what the schema calls sensitive and what the object
	 * tools mask cannot come to mean different things.
	 *
	 * An external field is sensitive when the attribute it points at is. It
	 * carries the remote value, so reading it is reading that attribute, and
	 * the interface sits on the remote definition rather than on the field
	 * itself - which is why testing the interface alone let the value through
	 * unmasked. iTop's REST API resolves this the same way; the difference here
	 * is that resolving it in IsSensitive() rather than at the call site means
	 * every caller gets it, including the schema tool that reports which
	 * attributes are sensitive.
	 *
	 * $sClass is optional only so that the signature stayed compatible; pass it
	 * wherever it is known, because without it the outbound-token case below
	 * cannot be asked and the value goes out unmasked.
	 *
	 * @since 1.0.0 Takes the class too, for the attributes iTop stores a secret in without using a secret type.
	 */
	public static function IsSensitive(AttributeDefinition $oAttDef, ?string $sClass = null): bool
	{
		if ($oAttDef instanceof iAttributeNoGroupBy) {
			return true;
		}

		// The one case the type does not answer. iTop's two OAuth datamodels
		// store the same secrets in different types: Oauth2Client uses
		// AttributeEncryptedPassword, which is caught above, while OAuthClient
		// keeps refresh_token and token in AttributeText - so a live token
		// this instance authenticates to a mail provider with was reported in
		// clear. AccessGrantRoots names the classes and the attributes; see
		// OUTBOUND_SECRET_ATTRIBUTES for why that is a list and not a pattern.
		if ($sClass !== null && AccessGrantFamilies::IsOutboundSecret($sClass, $oAttDef->GetCode())) {
			return true;
		}

		if (!$oAttDef instanceof AttributeExternalField) {
			return false;
		}

		try {
			return MetaModel::GetAttributeDef($oAttDef->GetTargetClass(), $oAttDef->GetExtAttCode())
				instanceof iAttributeNoGroupBy;
		} catch (Throwable $e) {
			// A field whose target cannot be resolved is a broken datamodel,
			// not a licence to report it: masked is the safe reading.
			return true;
		}
	}
}
