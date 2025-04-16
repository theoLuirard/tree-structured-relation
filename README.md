# Tree Structured Relation

This package provides a way to manage tree-structured data relations in your application

## Features

- Easy to integrate
- Allow to retrieve direct children and parent and also all the descendant of one node or all the predecessors of one node
- Lightweight and efficient
- Configurable

## Installation

To install the package, use the following command:

```bash
composer require theoluirard/tree-structured-relation
```

## What to expects

This packages define a trait that you can add on your model class definition. It defines for the model some relation and method to retrieve children and parents. You can also retrieve easily all ancestors and descendant for a given node. 

### Integrity is ensure by referencing the parent id 

When a node is created, it references the ID of its parent node (null if it's a root node). This ensures the integrity of the tree structure by maintaining a clear parent-child relationship. If a parent node is deleted, all its child nodes can be easily identified and handled accordingly. This reference mechanism helps in maintaining the consistency and integrity of the hierarchical data.

### An explicit path option is provided 

Having the path is nice for querying easily but for a human eye it could be a bit unreadable. You could add an explicit path column (so a second path property) that is based on more human readable value of your model 

### Reading is done using the path



## **We use materialized path method to retrieve easily descendant and ancestors**

The **Materialized Path** method is a way of storing hierarchical data (like a tree structure) in a relational database using a **single table**, where each row represents a node in the hierarchy. It is an alternative to other methods like **Adjacency List**, **Nested Sets**, and **Closure Table**.

---

### **How Materialized Path Works**
Each node stores its **full path** from the root, typically as a string, instead of just a reference to a parent node. This makes querying hierarchical relationships efficient.

### **Table Structure**
A typical table might look like this:

| id  | name     | parent_id| path        | explicit_path |
|-----|----------|----------|-------------|---------------|
| 1   | Root     | NULL     | /1          | /Root         |
| 2   | A        | 1        | /1/2        | /Root/A       |
| 3   | B        | 1        | /1/3        | /Root/B       |
| 4   | A1       | 2        | /1/2/4      | /Root/A/A1    |
| 5   | A2       | 2        | /1/2/5      | /Root/A/A2    |
| 6   | B1       | 3        | /1/3/6      | /Root/B/B1    |

Here:
- The `parent_id` ensures the integrity of the hierarchy
- The `path` column stores the full hierarchical path.
- The `explicit_path` column stores a human readable path 
- The delimiter (`/` in this case) separates different levels of the hierarchy.

---

## **Advantages of Materialized Path**
✅ **Fast subtree retrieval** (single query using `LIKE`).  
✅ **Efficient insertions and deletions** (no need to update sibling nodes).  
✅ **Easier to understand compared to Nested Sets.**  
✅ **Good indexing support** with `VARCHAR` paths.  

---

## **Disadvantages**
❌ **Path Updates Can Be Expensive** – Moving a node requires updating all descendant rows.  
❌ **Limited Depth Handling** – If stored as `VARCHAR`, a very deep hierarchy can cause storage issues.  
❌ **Indexing Constraints** – Indexing on a variable-length path column can be inefficient in large datasets.

---

## **When to Use Materialized Path?**
- When you need **fast subtree queries**.
- When insertions and deletions are frequent, but moves are rare.
- When your hierarchy is not extremely deep.

If you need **frequent node moves** or a **very large tree**, you might consider **Closure Tables** instead.

---

## Configuration

### Add the trait to your model 

Simply add the trait to your model definition 

```php

<?php

namespace App\Models;

use theoLuirard\laravelGetTableName\Traits\HasTreeStructure;;
use Illuminate\Database\Eloquent\Model;

class Domaine extends Model
{
    use HasTreeStructure;
}

```

### Migrations


You should define a column that point to the primary key (here `parent_id`). You also need a varchar column to store the path (here `path`). You may want a varchar column to store the explicit path (`explicit_path`).

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('path');
            $table->string('explicit_path'); // Optionnal
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('categories')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('categories');
    }
}
```


❗️ Also be careful on the length of your varchar column, they should be long enought to hold the full path

### Configure column names and separator

You can set the column names for the parent primary key column name, for the path column name, for the explicit path column name (if you want to store it in your DB), and for the operator used in both path. Be careful when choosing the operator, he should not be mix with the primary key

```php

<?php

namespace App\Models;

use App\Models\Traits\HasTreeStructure;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasTreeStructure;

    /**
     * The parent column name 
     * 
     * @var string
     */
    public string $parent_column_name = 'parent_id';

    /**
     * The path column name 
     * 
     * @var string
     */
    public string $path_column_name = 'path';

    /**
     * The separator used in path 
     * 
     * @var string 
     */
    public string $path_separator = '/';

    /**
     * The property name used in explicit path 
     * 
     * @var string 
     */
    public string $property_for_explicit_path = 'name';

    /**
     * The explicit_path column name, if this column is not in the table, it will be computed
     */
    public ?string $explicit_path_column_name = null;

}

```

## Usage

### Retrieving 

To get the collection of direct children, those which have the current node as parent 
```php
$category->direct_children
```

To get the parent of the current node
```php
$category->parent
```

To retrieve a collection of all the parents
```php
$category->parents
```

To retrieve a collection of all the children (direct children + children of the children and so on)
```php
$category->children
```

To retrieve a collection of all the siblings nodes (nodes that have the same parent)
```php
$category->siblings
```

### Querying 

As the hirearchy is define with relation by the trait you can query those relations

```php

// To filter
Category::whereHas("direct_children.name", "B1")->get();

// To eager load 
Category::with("parent")->get();

// Aggregate function 
Category::withCount('children')->get();

```

### Methods 

The trait define some methods to get some information about the nodes 

```php
$category->isRoot(); // True if it doesn't have parent

$category->isLeaf(); // True if it doesn't have children

$category1->isSiblingOf($category2); // True if both have the same direct parent

$category1->isParentOf($category2); // True if $category2 parent_id is $category1 id

$category1->isAncestorOf($category2); // True if $category2 is a children (even not a direct children) of $category 1

$category1->isChildOf($category2); // True if $category1 parent_id is $category2 id

$category1->isDescendantOf($category2);// True if $category1 is a children (even not a direct children) of $category 2
```

The trait define some methods to set parent and direct children

```php

$category1->setAsChildOf($category2); // Define $category1 as a direct children of $category2

$category1->setAsParentOf($category2); // Define $category1 as the parent of $category2

$category->setAsRoot(); // Define $category as a root node

$category->setAsChildOf(null); // Alias of $category->setAsRoot();

```


The trait also define methods to update the path

```php
$category->refreshPath(); // Compute the path and save it (for the current node and all his children)

Category::computeAllPath(); // Compute the path for all the nodes 
```


## Contributing

Contributions are welcome! Please open an issue or submit a pull request.

## License

This project is licensed under the MIT License.