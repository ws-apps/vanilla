<?php if (!defined('APPLICATION')) exit();

/// <summary>
/// Manages discussion categories.
/// </summary>
class CategoryModel extends Model {
   
   /// <summary>
   /// Class constructor. Defines the related database table name.
   /// </summary>
   public function __construct() {
      parent::__construct('Category');
   }
   public function Delete($Category, $ReplacementCategoryID) {
      // Don't do anything if the required category object & properties are not defined.
      if (
         !is_object($Category)
         || !property_exists($Category, 'CategoryID')
         || !property_exists($Category, 'ParentCategoryID')
         || !property_exists($Category, 'AllowDiscussions')
         || !property_exists($Category, 'Name')
         || $Category->CategoryID <= 0
      ) {
         throw new Exception(Gdn::Translate('Invalid category for deletion.'));
      } else {
         // Remove category permissions
         $PermissionIDData = $this->SQL
            ->Select('PermissionID')
            ->From('Permission')
            ->Where('JunctionTable', 'Category')
            ->Get()
            ->ResultArray();
         $PermissionIDs = ConsolidateArrayValuesByKey($PermissionIDData, 'PermissionID');
         
         if (count($PermissionIDs) > 0) {
            $this->SQL
               ->WhereIn('PermissionID', $PermissionIDs)
               ->Where('JunctionID', $Category->CategoryID)
               ->Delete('RolePermission');
         }
         
         // If there is a replacement category...
         if ($ReplacementCategoryID > 0) {
            // Update children categories
            $this->SQL
               ->Update('Category')
               ->Set('ParentCategoryID', $ReplacementCategoryID)
               ->Where('ParentCategoryID', $Category->CategoryID)
               ->Put();
               
            // Update discussions
            $this->SQL
               ->Update('Discussion')
               ->Set('CategoryID', $ReplacementCategoryID)
               ->Where('CategoryID', $Category->CategoryID)
               ->Put();
               
            // Update the discussion count
            $Count = $this->SQL
               ->Select('DiscussionID', 'count', 'DiscussionCount')
               ->From('Discussion')
               ->Where('CategoryID', $ReplacementCategoryID)
               ->Get()
               ->FirstRow()
               ->DiscussionCount;
               
            if (!is_numeric($Count))
               $Count = 0;
               
            $this->SQL
               ->Update('Category')->Set('CountDiscussions', $Count)
               ->Where('CategoryID', $ReplacementCategoryID)
               ->Put();
         } else {
            // Delete comments in this category
            $this->SQL->From('Comment')
               ->Join('Discussion d', 'Comment.DiscussionID = d.DiscussionID')
               ->Delete('Comment', array('d.CategoryID' => $Category->CategoryID));
               
            // Delete discussions in this category
            $this->SQL->Delete('Discussion', array('CategoryID' => $Category->CategoryID));
         }
         
         // Delete the category
         $this->SQL->Delete('Category', array('CategoryID' => $Category->CategoryID));
         
         // If there are no parent categories left, make sure that all other
         // categories are not assigned
         if ($this->SQL
            ->Select('CategoryID')
            ->From('Category')
            ->Where('AllowDiscussions', '0')
            ->Get()
            ->NumRows() == 0) {
            $this->SQL
               ->Update('Category')
               ->Set('ParentCategoryID', 'null', FALSE)
               ->Put();
         }
         
         // If there is only one category, make sure that Categories are not used
         $CountCategories = $this->Get()->NumRows();
         $Config = Gdn::Factory(Gdn::AliasConfig);
         $Config->Load(PATH_CONF . DS . 'config.php', 'Save');
         $Config->Set('Vanilla.Categories.Use', $CountCategories > 1, TRUE, 'ForSave');
         $Config->Save();
      }
      // Make sure to reorganize the categories after deletes
      $this->Organize();
   }   

   public function GetID($CategoryID) {
      return $this->SQL->GetWhere('Category', array('CategoryID' => $CategoryID))->FirstRow();
   }

   public function Get($OrderFields = '', $OrderDirection = 'asc', $Limit = FALSE, $Offset = FALSE) {
      return $this->SQL
         ->Select('c.ParentCategoryID, c.CategoryID, c.Name, c.Description, c.CountDiscussions, c.AllowDiscussions')
         ->From('Category c')
         ->BeginWhereGroup()
         ->Permission('c', 'CategoryID', 'Vanilla.Discussions.View')
         ->EndWhereGroup()
         ->OrWhere('AllowDiscussions', '0')
         ->OrderBy('Sort', 'asc')
         ->Get();
   }
   
   public function GetFull($CategoryID = '') {
      $this->SQL
         ->Select('c.CategoryID, c.Description, c.CountDiscussions')
         ->Select("' &bull; ', p.Name, c.Name", 'concat_ws', 'Name')
         ->From('Category c')
         ->Join('Category p', 'c.ParentCategoryID = p.CategoryID', 'left')
         ->Where('c.AllowDiscussions', '1')
         ->Permission('c', 'CategoryID', 'Vanilla.Discussions.View');

      if (is_numeric($CategoryID) && $CategoryID > 0)
         return $this->SQL->Where('c.CategoryID', $CategoryID)->Get()->FirstRow();
      else
         return $this->SQL->OrderBy('c.Sort')->Get();
   }

   public function GetFullByName($CategoryName) {
      return $this->SQL
         ->Select('c.CategoryID, c.Description, c.CountDiscussions')
         ->Select("' &bull; ', p.Name, c.Name", 'concat_ws', 'Name')
         ->From('Category c')
         ->Join('Category p', 'c.ParentCategoryID = p.CategoryID', 'left')
         ->Where('c.AllowDiscussions', '1')
         ->Permission('c', 'CategoryID', 'Vanilla.Discussions.View')
         ->Where('c.Name', $CategoryName)
         ->Get()
         ->FirstRow();
   }

   public function HasChildren($CategoryID) {
      $ChildData = $this->SQL
         ->Select('CategoryID')
         ->From('Category')
         ->Where('ParentCategoryID', $CategoryID)
         ->Get();
      return $ChildData->NumRows() > 0 ? TRUE : FALSE;
   }
   
   /// <summary>
   /// Organizes the category table so that all child categories are sorted
   /// below the appropriate parent category (they can get out of wack when
   /// parent categories are deleted and their children are re-assigned to a new
   /// parent category).
   /// </summary>
   public function Organize() {
      // Load all categories
      $CategoryData = $this->Get('Sort');
      $ParentsExist = FALSE;
      foreach ($CategoryData->Result() as $Category) {
         if ($Category->AllowDiscussions == '0')
            $ParentsExist = TRUE;
      }
      // Only reorder if there are parent categories present.
      if ($ParentsExist) {
         // If parent categories exist, make sure that child
         // categories fall underneath parent categories
         // and when a child appears under a parent, it becomes a child of that parent.
         $FirstParent = FALSE;
         $CurrentParent = FALSE;
         $Orphans = array();
         $i = 0;
         foreach ($CategoryData->Result() as $Category) {
            if ($Category->AllowDiscussions == '0')
               $CurrentParent = $Category;
               
            // If there hasn't been a parent yet OR
            // $Category isn't a parent category, and it is not a child of the
            // current parent, add it to the orphans collection
            if (!$CurrentParent) {
               $Orphans[] = $Category->CategoryID;
            } else if ($Category->CategoryID != $CurrentParent->CategoryID
               && $Category->ParentCategoryID != $CurrentParent->CategoryID) {
               // Make this category a child of the current parent and assign the sort
               $i++;
               $this->Update(
                  array(
                     'ParentCategoryID' => $CurrentParent->CategoryID,
                     'Sort' => $i
                  ),
                  array('CategoryID' => $Category->CategoryID)
               );
            } else {
               // Otherwise, assign the sort
               $i++;
               $this->Update(array('Sort' => $i), array('CategoryID' => $Category->CategoryID));
            }
         }
         // And now sort the orphans and assign them to the last parent
         foreach ($Orphans as $Key => $ID) {
            $i++;
            $this->Update(array('Sort' => $i, 'ParentCategoryID' => $CurrentParent->CategoryID), array('CategoryID' => $ID));
         }
      }
   }
   
   /**
    * Saves the category.
    *
    * @param array $FormPostValue The values being posted back from the form.
    */
   public function Save($FormPostValues) {
      // Define the primary key in this model's table.
      $this->DefineSchema();

      $CategoryID = ArrayValue('CategoryID', $FormPostValues);
      $NewName = ArrayValue('Name', $FormPostValues, '');
      $Insert = $CategoryID > 0 ? FALSE : TRUE;
      if ($Insert)
         $this->AddInsertFields($FormPostValues);               

      $this->AddUpdateFields($FormPostValues);
      
      // Validate the form posted values
      if ($this->Validate($FormPostValues, $Insert)) {
         $Fields = $this->Validation->SchemaValidationFields();
         $Fields = RemoveKeyFromArray($Fields, 'CategoryID');
         $AllowDiscussions = ArrayValue('AllowDiscussions', $Fields) == '1' ? TRUE : FALSE;
         $Fields['AllowDiscussions'] = $AllowDiscussions ? '1' : '0';

         if ($Insert === FALSE) {
            $OldCategory = $this->GetID($CategoryID);
            $AllowDiscussions = $OldCategory->AllowDiscussions; // Force the allowdiscussions property
            $Fields['AllowDiscussions'] = $AllowDiscussions ? '1' : '0';
            $this->Update($Fields, array('CategoryID' => $CategoryID));
            
         } else {
            // Make sure this category gets added to the end of the sort
            $SortData = $this->SQL
               ->Select('Sort')
               ->From('Category')
               ->OrderBy('Sort', 'desc')
               ->Limit(1)
               ->Get()
               ->FirstRow();
            $Fields['Sort'] = $SortData ? $SortData->Sort + 1 : 1;            
            $CategoryID = $this->Insert($Fields);
            
            if ($AllowDiscussions) {
               // If there are any parent categories, make this a child of the last one
               $ParentData = $this->SQL
                  ->Select('CategoryID')
                  ->From('Category')
                  ->Where('AllowDiscussions', '0')
                  ->OrderBy('Sort', 'desc')
                  ->Limit(1)
                  ->Get();
               if ($ParentData->NumRows() > 0) {
                  $this->SQL
                     ->Update('Category')
                     ->Set('ParentCategoryID', $ParentData->FirstRow()->CategoryID)
                     ->Where('CategoryID', $CategoryID)
                     ->Put();
               }               
            } else {
               // If there are any categories without parents, make this one the parent
               $this->SQL
                  ->Update('Category')
                  ->Set('ParentCategoryID', $CategoryID)
                  ->Where('ParentCategoryID is null')
                  ->Where('AllowDiscussions', '1')
                  ->Put();
            }
            $this->Organize();
         }
      } else {
         $CategoryID = FALSE;
      }
      return $CategoryID;
   }
}