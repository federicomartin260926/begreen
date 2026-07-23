# Usuarios, membresías de proyecto e invitaciones

Este documento describe el modelo implementado actualmente para usuarios y acceso a proyectos. También delimita las decisiones necesarias antes de incorporar un botón `+` de colaboradores en los proyectos.

No define todavía organizaciones, permisos nuevos ni flujos de invitación: esas capacidades se describen como alternativas futuras.

## 1. Estado actual

### Registro público y verificación

`App\Controller\RegistrationController` crea los usuarios registrados públicamente mediante `App\Form\RegistrationForm`.

- El formulario recoge datos personales, email, aceptación de condiciones y contraseña; no permite elegir un rol.
- La entidad `App\Entity\User` añade siempre `ROLE_USER` a los roles efectivos del usuario.
- Tras el alta se envía un email de confirmación mediante `App\Security\EmailVerifier`.
- La cuenta queda sin verificar hasta completar el enlace; `App\Security\UserChecker` impide el acceso de usuarios no verificados.
- No existe una invitación por email, activación desde proyecto ni aceptación de una asignación.

La creación desde `App\Controller\Admin\AdminUserController` es un flujo administrativo independiente. Permite definir roles y el estado de verificación, pero no envía una invitación ni un correo de verificación.

### Roles técnicos, etiquetas y jerarquía

Los roles se configuran en `config/packages/security.yaml`; las etiquetas administrativas se definen en `App\Form\UserType` y sus traducciones.

| Rol técnico | Etiqueta visible actual | Comportamiento efectivo |
| --- | --- | --- |
| `ROLE_SUPER_ADMIN` | Super Administrador | Hereda `ROLE_ADMIN` y `ROLE_USER`. Gestiona usuarios y sus asignaciones desde Administración. |
| `ROLE_ADMIN` | Administrador | Hereda `ROLE_USER`. Tiene acceso global a proyectos mediante los voters, pero no puede gestionar usuarios en `AdminUserController`. |
| `ROLE_MANAGER` | Gestor | Existe como rol asignable, pero no forma parte de la jerarquía ni concede permisos globales de gestión de miembros. Tiene comprobaciones puntuales de interfaz. |
| `ROLE_USER` | Editor | Rol efectivo por defecto y acceso base al backend autenticado. |
| `ROLE_ALLOWED_TO_SWITCH` | — | Rol técnico disponible para la impersonación configurada en el firewall. |

`ROLE_EDITOR` no existe como rol técnico. La etiqueta «Editor» corresponde actualmente a `ROLE_USER`.

La jerarquía efectiva es:

```text
ROLE_SUPER_ADMIN -> ROLE_ADMIN -> ROLE_USER
ROLE_MANAGER
ROLE_USER
```

`ROLE_MANAGER` no hereda `ROLE_ADMIN` ni aporta, por sí mismo, facultades para listar usuarios, asignar proyectos o invitar colaboradores.

### Permisos actuales

- `ROLE_SUPER_ADMIN`: puede listar, crear, editar y eliminar usuarios; asignar y desasignar proyectos desde `AdminUserController`; y acceder a todos los proyectos.
- `ROLE_ADMIN`: puede acceder a todos los proyectos y superar los voters de proyecto, plan y emisiones; no supera los controles explícitos de `ROLE_SUPER_ADMIN` de la gestión de usuarios.
- `ROLE_MANAGER`: accede como usuario autenticado y como miembro de proyecto cuando tiene una membresía. No tiene un permiso global de gestión de usuarios o miembros.
- `ROLE_USER`: accede al backend autenticado y a los proyectos para los que tiene una membresía.

`App\Security\ProjectVoter`, `PlanVoter` y `EmissionRecordVoter` permiten ver y editar a cualquier miembro asignado. Hoy no hay distinción efectiva entre propietario, gestor, editor o lector dentro de un proyecto.

## 2. Modelo usuario-proyecto

### Creador y acceso

`App\Entity\Project` mantiene dos relaciones diferentes:

- `Project.user`: usuario creador del proyecto. Puede ser nulo a nivel de modelo y se utiliza como referencia de creación y presentación.
- `Project.projectMemberships`: colección de `App\Entity\ProjectMembership`, que determina el acceso habitual de usuarios no administradores.

`ProjectMembership` enlaza un usuario y un proyecto. La pareja es única y contiene `projectRole`, cuyo valor por defecto actual es `member`.

Al crear o clonar un proyecto, `App\Controller\Backend\ProjectController` establece al usuario actual como creador y crea una membresía con `projectRole = owner`.

### Propietario y miembro

El creador y el miembro son conceptos distintos:

- El creador se guarda en `Project.user`.
- El acceso se resuelve por `ProjectMembership`.
- El creador creado por el flujo normal recibe también una membresía `owner`.

Aunque existen los valores `owner` y `member`, `projectRole` no genera todavía permisos diferenciados. Los voters tratan por igual a cualquier miembro para ver y editar el proyecto y sus recursos asociados.

### Visibilidad y proyecto activo

Para usuarios no administradores, `App\Repository\ProjectMembershipRepository::projectsOf()` determina los proyectos visibles. `App\Controller\Backend\ProjectController` y `App\EventSubscriber\ActiveProjectSubscriber` reutilizan este criterio.

Los administradores tienen visibilidad global de proyectos. Un proyecto creado con los flujos actuales es visible para su creador porque se crea la membresía `owner`; un creador sin membresía no tendría acceso normal solo por figurar en `Project.user`.

### Desasignación y restricciones del creador

La desasignación elimina la entidad `ProjectMembership`; no elimina al usuario ni el proyecto.

El flujo administrativo bloquea la eliminación de la membresía cuando el usuario es el creador de `Project.user`. También bloquea la eliminación administrativa de un usuario que sea creador de proyectos. Estas restricciones están en `App\Controller\Admin\AdminUserController`.

No existe todavía una operación funcional para transferir la propiedad del proyecto, convertir un propietario en miembro o gestionar el abandono del creador.

## 3. Administración actual

La asignación se gestiona en Administración > Usuarios > Proyectos, mediante `App\Controller\Admin\AdminUserController` y `templates/admin/user/form.html.twig`.

Flujo actual:

1. Un Super Administrador abre un usuario.
2. La vista consulta sus membresías y calcula los proyectos aún no asignados.
3. El modal envía un `POST` a la ruta `admin_user_assign_project`.
4. El controlador valida el token CSRF, la existencia del proyecto y que no haya una membresía duplicada.
5. Se crea una `ProjectMembership` con el rol `member`.
6. La desasignación usa una ruta POST independiente, un token CSRF propio y comprueba que la membresía pertenece al usuario indicado.

Todos estos métodos exigen expresamente `ROLE_SUPER_ADMIN`.

La entidad y `App\Repository\ProjectMembershipRepository` se pueden reutilizar en un futuro flujo de colaboración. El formulario administrativo no debe exponerse directamente al área cliente: carga usuarios y proyectos sin un ámbito de organización/empresa y solo está diseñado para personal interno con privilegios globales.

## 4. Limitaciones actuales

- No existe una entidad `Organization`, `Company`, `Client`, `Team` o `Tenant` relacionada con `User`.
- `App\Entity\ProjectCompany` describe empresas participantes en un proyecto; no es una organización propietaria de usuarios ni delimita permisos.
- No hay roles efectivos por proyecto, aunque exista el campo `projectRole`.
- No hay invitaciones, aceptación de acceso, caducidad, reenvío ni incorporación automática tras el registro.
- No existe un selector seguro de usuarios para clientes o Gestores.
- Exponer una búsqueda global de usuarios a un creador o Gestor puede revelar datos de usuarios de otros clientes.
- Cualquier miembro asignado obtiene actualmente capacidad de edición completa mediante los voters.

Por estas razones, el botón `+` no debe implementarse como autoservicio para clientes antes de definir organización/equipo, ámbito de consulta y permisos por proyecto.

## 5. Alternativas futuras

Las estimaciones siguientes son orientativas y dependen de la definición funcional final, el diseño de interfaz y las reglas de autorización acordadas.

### A. Administración centralizada

- Botón `+` visible solo para `ROLE_SUPER_ADMIN`.
- Permite asignar usuarios ya registrados a un proyecto usando `ProjectMembership`.
- Reutiliza las validaciones y la relación actual, con un flujo de interfaz orientado al proyecto.
- No ofrece autonomía al cliente ni incluye invitaciones.
- Estimación orientativa: **8–14 horas**.

### B. Equipos y usuarios existentes

- Añade una organización/equipo propietario del proyecto.
- Incorpora una relación de membresía de organización, por ejemplo `OrganizationMembership`.
- Relaciona cada proyecto con su organización propietaria, por ejemplo `Project.organization`.
- Define roles efectivos por proyecto y los aplica en voters y acciones sensibles.
- Permite que propietario o gestor autorizado asigne solo usuarios ya registrados dentro de su ámbito.
- No incluye todavía invitaciones externas.
- Estimación orientativa: **30–50 horas**.

### C. Invitaciones completas

- Parte del modelo de organizaciones y roles de la alternativa B.
- Añade invitación por email para usuarios existentes o nuevos.
- Requiere token seguro, caducidad, reenvío, cancelación y aceptación.
- Tras el alta, acceso o verificación, incorpora automáticamente al usuario al proyecto con el rol autorizado.
- Requiere pantallas de miembros e invitaciones, plantillas de correo y controles contra escalada de privilegios.
- Estimación orientativa: **80–130 horas**.

## 6. Recomendación actual

No se recomienda implementar todavía autoservicio de colaboradores para creadores o Gestores.

Mientras no exista un ámbito de organización/equipo, la opción segura es mantener la asignación centralizada para `ROLE_SUPER_ADMIN`. Si se necesita una primera iteración del botón `+`, debe limitarse a ese rol y a usuarios ya registrados.

Antes de avanzar, debe decidirse si el objetivo es:

1. asignación administrativa centralizada;
2. gestión de usuarios existentes por cada cliente;
3. invitaciones completas por email.

Las opciones B y C requieren desarrollo adicional y cambios estructurales en el modelo de datos y autorización.

## 7. Preguntas pendientes para cliente

- ¿Quién debe ver y utilizar el botón `+`?
- ¿La primera versión debe asignar solo usuarios ya registrados?
- ¿El cliente debe poder invitar colaboradores por email?
- ¿Qué roles deben existir dentro de cada proyecto y qué puede hacer cada uno?
- ¿Hay una organización propietaria de cada proyecto?
- ¿Pueden participar usuarios externos a esa organización?
- ¿Qué datos deben mostrarse en el selector de usuarios?
- ¿El botón debe aparecer solo en Home o también en edición y detalle del proyecto?

## Referencias de implementación

- `app/src/Entity/User.php`
- `app/src/Entity/Project.php`
- `app/src/Entity/ProjectMembership.php`
- `app/src/Entity/ProjectCompany.php`
- `app/src/Repository/ProjectMembershipRepository.php`
- `app/src/Controller/RegistrationController.php`
- `app/src/Controller/Backend/ProjectController.php`
- `app/src/Controller/Admin/AdminUserController.php`
- `app/src/Security/ProjectVoter.php`
- `app/src/Security/PlanVoter.php`
- `app/src/Security/EmissionRecordVoter.php`
- `app/src/Security/UserChecker.php`
- `app/src/Security/EmailVerifier.php`
- `app/src/Form/UserType.php`
- `app/src/EventSubscriber/ActiveProjectSubscriber.php`
- `app/config/packages/security.yaml`
- `app/templates/admin/user/form.html.twig`
